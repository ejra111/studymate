<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\Activity;
use App\Models\StudyGroup;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class UserController extends Controller
{
    public function show($id)
    {
        $user = User::with(['program', 'courses'])->find($id);
        if (!$user) return response()->json(['message' => 'User tidak ditemukan.'], 404);
        return response()->json($user);
    }

    public function update(Request $request, $id)
    {
        $user = User::find($id);
        if (!$user) return response()->json(['message' => 'User tidak ditemukan.'], 404);

        $data = $request->only([
            'name',
            'email',
            'university',
            'programName',
            'semester',
            'studentId',
            'programId',
            'bio',
            'interests',
            'courseIds',
            'availability',
            'avatarColor',
            'avatarUrl'
        ]);
        
        $updateData = [];
        if (isset($data['name'])) $updateData['name'] = $data['name'];
        if (isset($data['email'])) $updateData['email'] = strtolower(trim($data['email']));
        if (isset($data['university'])) $updateData['university'] = strtoupper($data['university']);
        if (isset($data['programName'])) $updateData['program_name'] = strtoupper($data['programName']);
        if (isset($data['semester'])) $updateData['semester'] = (int) $data['semester'];
        if (isset($data['studentId'])) $updateData['student_id'] = $data['studentId'];
        if (isset($data['programId'])) $updateData['program_id'] = $data['programId'];
        if (isset($data['bio'])) $updateData['bio'] = $data['bio'];
        if (isset($data['interests'])) $updateData['interests'] = array_map('strtoupper', $data['interests']);
        if (isset($data['availability'])) $updateData['availability'] = $data['availability'];
        if (isset($data['avatarColor'])) $updateData['avatar_color'] = $data['avatarColor'];
        if (isset($data['avatarUrl'])) $updateData['avatar_url'] = $data['avatarUrl'];

        $user->update($updateData);

        if (isset($data['courseIds'])) {
            $user->courses()->sync($data['courseIds']);
        }

        $user = $user->fresh(['program', 'courses']);

        Activity::create([
            'id' => (string) Str::uuid(),
            'actor_id' => $user->id,
            'type' => 'profile.update',
            'message' => "{$user->name} memperbarui profil akademik",
        ]);

        return response()->json($user);
    }

    public function uploadAvatar(Request $request, $id)
    {
        $user = User::find($id);
        if (!$user) return response()->json(['message' => 'User tidak ditemukan.'], 404);

        $request->validate([
            'avatar' => 'required|image|mimes:jpg,jpeg,png,webp|max:2048',
        ]);

        $file = $request->file('avatar');
        $dir = public_path('avatars');
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        $name = (string) Str::uuid() . '.' . $file->getClientOriginalExtension();
        $file->move($dir, $name);
        $url = rtrim($request->getSchemeAndHttpHost(), '/') . "/avatars/{$name}";

        $old = $user->avatar_url;
        if ($old) {
            $basename = basename(parse_url($old, PHP_URL_PATH) ?? '');
            if ($basename) {
                $oldPath = public_path('avatars/' . $basename);
                if (is_file($oldPath)) {
                    @unlink($oldPath);
                }
            }
        }

        $user->avatar_url = $url;
        $user->save();

        Activity::create([
            'id' => (string) Str::uuid(),
            'actor_id' => $user->id,
            'type' => 'profile.avatar.update',
            'message' => "{$user->name} memperbarui foto profil",
        ]);

        return response()->json($user->fresh()->load(['program', 'courses']));
    }
    public function dashboard($userId)
    {
        $user = User::with(['program', 'courses', 'studyGroups', 'friends'])->find($userId);
        if (!$user) return response()->json(['message' => 'User tidak ditemukan.'], 404);

        $myGroups = $user->studyGroups()
            ->with(['owner', 'course', 'location', 'members'])
            ->latest()
            ->get();

        $createdGroups = StudyGroup::where('owner_id', $userId)->count();

        $candidates = User::where('id', '!=', $userId)
            ->where('role', 'student')
            ->with(['program', 'studyGroups'])
            ->get()
            ->map(function ($candidate) use ($user) {
                $common = $candidate->studyGroups->intersect($user->studyGroups);
                $score = $common->count() * 25;
                return [
                    'id' => $candidate->id,
                    'name' => $candidate->name,
                    'bio' => $candidate->bio,
                    'program' => $candidate->program,
                    'avatarColor' => $candidate->avatarColor,
                    'avatarUrl' => $candidate->avatarUrl,
                    'score' => $score,
                    'commonGroupsCount' => $common->count(),
                ];
            })
            ->sortByDesc('score')
            ->take(6)
            ->values();

        $compatibilitySignal = (int) ($candidates->first()['score'] ?? 0);

        return response()->json([
            'user' => $user,
            'stats' => [
                'joinedGroups' => $myGroups->count(),
                'createdGroups' => $createdGroups,
                'selectedCourses' => $user->courses->count(),
                'compatibilitySignal' => $compatibilitySignal,
            ],
            'recommendations' => $candidates,
            'upcomingGroups' => $myGroups->take(6)->values(),
            'friends' => $user->friends->map(function ($f) {
                return [
                    'id' => $f->id,
                    'name' => $f->name,
                    'avatarColor' => $f->avatarColor,
                    'avatarUrl' => $f->avatarUrl,
                    'program' => $f->program_name,
                ];
            }),
            'recentActivities' => Activity::where('actor_id', $userId)->latest()->limit(8)->get(),
        ]);
    }

    public function friends($userId)
    {
        $user = User::with('friends')->find($userId);
        if (!$user) return response()->json(['message' => 'User tidak ditemukan.'], 404);
        return response()->json($user->friends);
    }
}
