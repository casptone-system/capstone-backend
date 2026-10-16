<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class ProfilePhoto
{
    public static function store(User $user, UploadedFile $file): void
    {
        $previousPath = $user->profile_photo;
        $path = $file->store('profile_photos', 'public');
        $binary = file_get_contents($file->getRealPath());

        $user->forceFill([
            'profile_photo' => $path,
            'profile_photo_blob' => $binary === false ? null : $binary,
            'profile_photo_mime' => $file->getMimeType() ?: 'image/jpeg',
        ])->save();

        if (! empty($previousPath) && $previousPath !== $path) {
            Storage::disk('public')->delete($previousPath);
        }
    }

    public static function url(User $user): ?string
    {
        if (empty($user->profile_photo) && empty($user->profile_photo_blob)) {
            return null;
        }

        $version = $user->updated_at?->timestamp ?? time();

        return url('/api/users/'.$user->id.'/profile-photo').'?v='.$version;
    }

    public static function response(User $user): StreamedResponse|\Illuminate\Http\Response
    {
        $disk = Storage::disk('public');
        $path = (string) $user->profile_photo;

        if ($path !== '' && $disk->exists($path)) {
            return $disk->response($path, null, [
                'Cache-Control' => 'public, max-age=86400',
            ]);
        }

        if (! empty($user->profile_photo_blob)) {
            // Restore onto disk so subsequent requests are cheap.
            if ($path !== '') {
                try {
                    $disk->put($path, $user->profile_photo_blob);
                } catch (\Throwable) {
                    // Serving from memory is enough if disk write fails.
                }
            }

            return response($user->profile_photo_blob, 200, [
                'Content-Type' => $user->profile_photo_mime ?: 'image/jpeg',
                'Cache-Control' => 'public, max-age=86400',
            ]);
        }

        abort(404, 'Profile photo not found.');
    }
}
