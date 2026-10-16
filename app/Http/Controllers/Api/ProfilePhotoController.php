<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class ProfilePhotoController extends Controller
{
    public function store(Request $request)
    {
        $request->validate([
            'profile_photo' => 'required|image|mimes:jpg,jpeg,png,webp|max:2048',
        ]);

        $user = $request->user();

        if ($user->profile_photo_path) {
            Storage::disk('public')->delete($user->profile_photo_path);
        }

        $path = $request->file('profile_photo')
            ->store('profile-photos', 'public');

        $user->profile_photo_path = $path;
        $user->save();

        return response()->json([
            'message' => 'Profile picture updated.',
            'user' => $user->fresh(),
        ]);
    }
}
