<?php

namespace App\Services;

use App\Models\StudyGroup;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class StudyAiService
{
    public function buildStudyPlan(User $user): array
    {
        $user->loadMissing(['courses', 'studyGroups.location']);

        $availability = array_values($user->availability ?? []);
        $courses = $user->courses->values();

        if ($courses->isEmpty()) {
            return [
                'headline' => 'Belum ada rencana belajar yang bisa dibuat.',
                'summary' => 'Tambahkan mata kuliah aktif dulu agar AI Planner bisa menyusun slot belajar personal.',
                'recommendedFocusWindow' => null,
                'sessions' => [],
                'tips' => [
                    'Pastikan kamu mengetik KODE atau NAMA mata kuliah yang sesuai di Profil.',
                    'Lengkapi profil dan mata kuliah aktif.',
                    'Tambahkan availability mingguan agar rekomendasi lebih akurat.',
                ],
            ];
        }

        if (empty($availability)) {
            $availability = ['SENIN 19:00', 'RABU 19:00', 'JUMAT 19:00'];
        }

        $courseBuckets = $courses->map(function ($course) use ($availability, $user) {
            $priority = $this->coursePriority($course->name, $user->interests ?? [], $user->bio, (int) ($user->semester ?? 1));
            return [
                'course' => $course,
                'priority' => $priority,
            ];
        });

        $courseBuckets = $courseBuckets->sortByDesc('priority')->values();

        $sessions = [];
        foreach ($courseBuckets as $index => $bucket) {
            $slot = $availability[$index % count($availability)];
            $course = $bucket['course'];
            $sessions[] = [
                'courseId' => $course->id,
                'courseCode' => $course->code,
                'courseName' => $course->name,
                'slot' => $slot,
                'durationMinutes' => $bucket['priority'] >= 75 ? 120 : 90,
                'focus' => $this->focusSuggestion($course->name),
                'priority' => $bucket['priority'],
            ];
        }

        $focusWindow = $sessions[0]['slot'] ?? null;
        $tips = [
            'Mulai dari mata kuliah dengan prioritas tertinggi terlebih dahulu.',
            'Sisihkan 10–15 menit sebelum sesi untuk review materi sebelumnya.',
            'Pakai Smart Match untuk mencari partner yang punya slot waktu serupa.',
        ];

        if ($user->studyGroups->count() > 0) {
            $tips[] = 'Gabungkan sesi personal dengan jadwal grup agar ritme belajar lebih konsisten.';
        }

        return [
            'headline' => 'AI Study Planner siap dipakai.',
            'summary' => 'Rencana ini disusun dari mata kuliah aktif, minat, semester, dan availability yang kamu isi.',
            'recommendedFocusWindow' => $focusWindow,
            'sessions' => $sessions,
            'tips' => $tips,
        ];
    }

    /**
     * Summarize group discussion using Groq AI with fallback to keyword extraction
     */
    public function summarizeGroupDiscussion(StudyGroup $group, Collection $messages, bool $forceRefresh = false): array
    {
        $group->loadMissing(['course', 'members']);

        if ($messages->isEmpty()) {
            return [
                'headline' => 'Belum ada percakapan untuk diringkas.',
                'summary' => 'Mulai diskusi di chat grup terlebih dahulu.',
                'keywords' => [],
                'actionItems' => [],
                'deadlines' => [],
            ];
        }

        // Check cache (5 minutes TTL)
        $cacheKey = "group_summary_{$group->id}_{$messages->count()}";
        if (!$forceRefresh) {
            $cached = Cache::get($cacheKey);
            if ($cached) {
                return $cached;
            }
        }

        // Limit to 50 most recent messages
        $recentMessages = $messages->take(50);

        // Try Groq AI first
        $apiKey = config('services.groq.api_key');
        $model = config('services.groq.model', 'llama-3.3-70b-versatile');

        if ($apiKey) {
            try {
                $result = $this->summarizeWithGroq($group, $recentMessages, $apiKey, $model);
                if ($result) {
                    Cache::put($cacheKey, $result, now()->addMinutes(5));
                    return $result;
                }
            } catch (\Exception $e) {
                Log::warning('Groq summarization failed, falling back to keyword extraction', [
                    'error' => $e->getMessage(),
                ]);
            }
        }

        // Fallback to local keyword extraction
        $result = $this->summarizeLocally($group, $recentMessages);
        Cache::put($cacheKey, $result, now()->addMinutes(5));
        return $result;
    }

    /**
     * Use Groq API to generate group discussion summary
     */
    private function summarizeWithGroq(StudyGroup $group, Collection $messages, string $apiKey, string $model): ?array
    {
        $chatLog = $messages->map(function ($msg) {
            $userName = $msg->user?->name ?? 'Unknown';
            return "{$userName}: {$msg->message}";
        })->implode("\n");

        $courseName = $group->course?->name ?? 'Umum';

        $prompt = <<<PROMPT
Kamu adalah asisten AI untuk platform belajar mahasiswa bernama StudyMate.

Berikut adalah percakapan dari grup belajar "{$group->title}" (mata kuliah: {$courseName}).

PERCAKAPAN:
{$chatLog}

Buatkan ringkasan percakapan dalam Bahasa Indonesia dengan format JSON berikut:
{
  "headline": "Judul ringkasan singkat (1 kalimat)",
  "summary": "Ringkasan isi percakapan (2-3 kalimat)",
  "keywords": ["kata kunci 1", "kata kunci 2", "maksimal 6"],
  "actionItems": ["tindak lanjut 1", "tindak lanjut 2"],
  "deadlines": ["deadline/tenggat waktu yang disebutkan"]
}

PENTING: Jawab HANYA dengan JSON valid tanpa markdown formatting atau teks tambahan.
PROMPT;

        $response = Http::withHeaders([
            'Authorization' => "Bearer {$apiKey}",
            'Content-Type' => 'application/json',
        ])
        ->timeout(20)
        ->post('https://api.groq.com/openai/v1/chat/completions', [
            'model' => $model,
            'messages' => [
                ['role' => 'user', 'content' => $prompt],
            ],
            'max_tokens' => 600,
            'temperature' => 0.3,
        ]);

        if ($response->failed()) {
            Log::warning('Groq summary API call failed', [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);
            return null;
        }

        $data = $response->json();
        $content = $data['choices'][0]['message']['content'] ?? null;

        if (!$content) {
            return null;
        }

        // Clean markdown formatting if present
        $content = preg_replace('/^```json\s*/i', '', trim($content));
        $content = preg_replace('/\s*```$/i', '', $content);

        $parsed = json_decode($content, true);

        if (!$parsed || !isset($parsed['summary'])) {
            Log::warning('Groq summary JSON parse failed', ['content' => $content]);
            return null;
        }

        return [
            'headline' => $parsed['headline'] ?? 'Ringkasan AI untuk diskusi grup.',
            'summary' => $parsed['summary'],
            'keywords' => array_slice($parsed['keywords'] ?? [], 0, 6),
            'actionItems' => array_slice($parsed['actionItems'] ?? [], 0, 5),
            'deadlines' => array_slice($parsed['deadlines'] ?? [], 0, 8),
            'messageCount' => $messages->count(),
            'source' => 'groq_ai',
        ];
    }

    /**
     * Fallback: local keyword extraction based summarization
     */
    private function summarizeLocally(StudyGroup $group, Collection $messages): array
    {
        $texts = $messages->pluck('message')->filter()->values();
        $combined = strtolower($texts->implode(' '));
        $keywords = $this->extractKeywords($combined);
        $actionItems = $this->extractActionItems($texts);
        $deadlines = $this->extractDeadlines($texts);

        $summaryParts = [];
        if ($group->course?->name) {
            $summaryParts[] = 'Diskusi berfokus pada ' . strtolower($group->course->name);
        }
        if (!empty($keywords)) {
            $summaryParts[] = 'topik yang paling sering muncul adalah ' . implode(', ', array_slice($keywords, 0, 4));
        }
        if (!empty($actionItems)) {
            $summaryParts[] = 'terdapat beberapa tindak lanjut yang perlu dikerjakan';
        }
        if (!empty($deadlines)) {
            $summaryParts[] = 'serta ada penanda waktu yang perlu diperhatikan';
        }

        return [
            'headline' => 'Ringkasan AI untuk diskusi grup.',
            'summary' => ucfirst(implode(', ', $summaryParts)) . '.',
            'keywords' => $keywords,
            'actionItems' => $actionItems,
            'deadlines' => $deadlines,
            'messageCount' => $messages->count(),
            'source' => 'local_keywords',
        ];
    }

    /**
     * AI Coach - fully powered by Groq API with conversation history
     */
    public function askCoach(User $user, string $message, array $history = []): array
    {
        $user->loadMissing(['courses', 'program']);

        $apiKey = config('services.groq.api_key');
        $model = config('services.groq.model', 'llama-3.3-70b-versatile');

        if (!$apiKey) {
            Log::error('Groq API key not configured');
            return [
                'message' => 'Maaf, AI Coach sedang tidak tersedia saat ini. API key belum dikonfigurasi. Silakan hubungi admin.',
                'timestamp' => now()->toIso8601String(),
                'sender' => 'AI Coach',
                'isError' => true,
            ];
        }

        try {
            $systemPrompt = $this->buildCoachSystemPrompt($user);

            // Build messages array with conversation history
            $apiMessages = [
                ['role' => 'system', 'content' => $systemPrompt],
            ];

            // Add conversation history (max 10 messages for context window)
            foreach (array_slice($history, -10) as $historyMsg) {
                $role = ($historyMsg['role'] ?? 'user');
                $content = ($historyMsg['content'] ?? '');
                if ($role && $content) {
                    $apiMessages[] = ['role' => $role, 'content' => $content];
                }
            }

            // Add current user message
            $apiMessages[] = ['role' => 'user', 'content' => $message];

            $response = Http::withHeaders([
                'Authorization' => "Bearer {$apiKey}",
                'Content-Type' => 'application/json',
            ])
            ->timeout(20)
            ->post('https://api.groq.com/openai/v1/chat/completions', [
                'model' => $model,
                'messages' => $apiMessages,
                'max_tokens' => 800,
                'temperature' => 0.7,
            ]);

            if ($response->failed()) {
                Log::warning('Groq API failed for coach', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);
                return [
                    'message' => 'Maaf, AI Coach sedang sibuk. Server API tidak merespon (HTTP ' . $response->status() . '). Coba lagi dalam beberapa saat. 🙏',
                    'timestamp' => now()->toIso8601String(),
                    'sender' => 'AI Coach',
                    'isError' => true,
                ];
            }

            $data = $response->json();
            $aiMessage = $data['choices'][0]['message']['content'] ?? null;

            if (!$aiMessage) {
                return [
                    'message' => 'Maaf, AI Coach tidak memberikan respons. Coba kirim pertanyaan lagi. 🙏',
                    'timestamp' => now()->toIso8601String(),
                    'sender' => 'AI Coach',
                    'isError' => true,
                ];
            }

            return [
                'message' => $aiMessage,
                'timestamp' => now()->toIso8601String(),
                'sender' => 'AI Coach',
            ];
        } catch (\Exception $e) {
            Log::error('Groq API exception in coach', ['error' => $e->getMessage()]);
            return [
                'message' => 'Maaf, terjadi kesalahan saat menghubungi AI Coach: ' . $e->getMessage() . '. Coba lagi nanti. 🙏',
                'timestamp' => now()->toIso8601String(),
                'sender' => 'AI Coach',
                'isError' => true,
            ];
        }
    }

    private function buildCoachSystemPrompt(User $user): string
    {
        $courseNames = $user->courses->pluck('name')->implode(', ') ?: 'belum diisi';
        $programName = $user->program?->name ?? $user->program_name ?? 'belum diisi';
        $semester = $user->semester ?? 'belum diisi';
        $interests = implode(', ', $user->interests ?? []) ?: 'belum diisi';
        $bio = $user->bio ?: 'belum diisi';

        return <<<PROMPT
Kamu adalah "AI Study Coach" di platform StudyMate, sebuah aplikasi belajar untuk mahasiswa Indonesia.

Profil mahasiswa yang sedang bertanya:
- Nama: {$user->name}
- Program Studi: {$programName}
- Semester: {$semester}
- Mata Kuliah Aktif: {$courseNames}
- Minat: {$interests}
- Bio: {$bio}

Aturan:
1. Jawab dalam Bahasa Indonesia yang ramah dan santai.
2. Berikan saran belajar yang spesifik dan actionable berdasarkan profil mahasiswa.
3. Jika ditanya tentang topik akademik (seperti programming, database, AI), berikan penjelasan yang jelas dan terstruktur.
4. Jangan terlalu panjang — maksimal 3-4 paragraf.
5. Jika relevan, sarankan fitur StudyMate seperti Smart Match atau Grup Belajar.
6. Bersikaplah supportif dan memotivasi.
PROMPT;
    }

    private function coursePriority(string $courseName, array $interests = [], ?string $bio = null, int $semester = 1): int
    {
        $priority = 55;
        $haystack = strtolower($courseName . ' ' . (string) $bio);

        foreach ($interests as $interest) {
            if ($interest && str_contains($haystack, strtolower($interest))) {
                $priority += 8;
            }
        }

        if (str_contains($haystack, 'tugas akhir') || str_contains($haystack, 'project')) {
            $priority += 10;
        }

        if ($semester >= 5) {
            $priority += 5;
        }

        return min($priority, 95);
    }

    private function focusSuggestion(string $courseName): string
    {
        $name = strtolower($courseName);

        return match (true) {
            str_contains($name, 'basis data') => 'Latihan query, ERD, dan normalisasi.',
            str_contains($name, 'web') => 'Kerjakan implementasi UI dan integrasi API.',
            str_contains($name, 'objek') => 'Ulangi konsep class, inheritance, dan practice coding.',
            str_contains($name, 'ai') || str_contains($name, 'kecerdasan') => 'Fokus pada konsep inti, studi kasus, dan evaluasi model.',
            default => 'Review materi inti, buat catatan ringkas, lalu latihan soal.',
        };
    }

    private function extractKeywords(string $text): array
    {
        $text = preg_replace('/[^a-z0-9\s]/', ' ', $text) ?? '';
        $tokens = preg_split('/\s+/', $text) ?: [];
        $stopwords = [
            'dan', 'yang', 'untuk', 'dengan', 'atau', 'saya', 'kami', 'kita', 'ini', 'itu', 'the',
            'ada', 'akan', 'jadi', 'sudah', 'belum', 'besok', 'nanti', 'grup', 'study', 'mate',
        ];

        $counts = [];
        foreach ($tokens as $token) {
            if (strlen($token) < 4 || in_array($token, $stopwords, true)) {
                continue;
            }
            $counts[$token] = ($counts[$token] ?? 0) + 1;
        }

        arsort($counts);
        return array_slice(array_keys($counts), 0, 6);
    }

    private function extractActionItems(Collection $texts): array
    {
        $patterns = '/(tolong|please|kerjakan|buat|review|cek|fix|rapikan|submit|kumpul|presentasi|update)/i';

        return $texts
            ->filter(fn ($text) => preg_match($patterns, (string) $text) === 1)
            ->map(fn ($text) => trim((string) $text))
            ->take(5)
            ->values()
            ->all();
    }

    private function extractDeadlines(Collection $texts): array
    {
        $results = [];
        $patterns = [
            '/\b(senin|selasa|rabu|kamis|jumat|sabtu|minggu|besok|lusa)\b/i',
            '/\b\d{1,2}[\/\-]\d{1,2}(?:[\/\-]\d{2,4})?\b/',
            '/\b\d{1,2}\.\d{2}\b/',
        ];

        foreach ($texts as $text) {
            foreach ($patterns as $pattern) {
                if (preg_match_all($pattern, (string) $text, $matches)) {
                    foreach (($matches[0] ?? []) as $match) {
                        $results[] = $match;
                    }
                }
            }
        }

        return array_values(array_unique(array_slice($results, 0, 8)));
    }
}
