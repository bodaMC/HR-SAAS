<?php

namespace App\Accounts;

use App\Models\Employee;
use App\Rbac\RbacDenied;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Phase 3.3 profile pictures. Local private disk, tenant-namespaced, server-generated names.
 * The upload is validated by its REAL content (finfo + getimagesize), limited in size and
 * dimensions, decoded and re-encoded with GD (strips metadata and any embedded payload) to a
 * square JPEG. SVG, GIF and every non-raster type are rejected. No client path or name is used.
 */
class AvatarService
{
    public const MAX_BYTES = 2 * 1024 * 1024;

    public const MAX_DIM = 4000;

    public const MIN_DIM = 64;

    public const SIZE = 512;

    private const MIMES = ['image/jpeg' => 'imagecreatefromjpeg', 'image/png' => 'imagecreatefrompng', 'image/webp' => 'imagecreatefromwebp'];

    private function disk()
    {
        return Storage::disk(config('filesystems.avatar_disk', 'local'));
    }

    public function path(Employee $e): ?string
    {
        return $e->avatar_path;
    }

    public function exists(Employee $e): bool
    {
        return $e->avatar_path !== null && $this->disk()->exists($e->avatar_path);
    }

    public function stream(Employee $e): ?string
    {
        return $this->exists($e) ? $this->disk()->path($e->avatar_path) : null;
    }

    /** Validates and stores; returns the new relative path (the caller saves it and deletes the old file). */
    public function store(Employee $employee, UploadedFile $file): string
    {
        if (! $file->isValid() || $file->getSize() === false || $file->getSize() > self::MAX_BYTES || $file->getSize() < 1) {
            throw new RbacDenied(422, 'image_too_large', __('accounts.image_too_large'));
        }
        $real = $file->getRealPath();
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($real);
        $info = $mime && isset(self::MIMES[$mime]) ? @getimagesize($real) : false;
        if (! $info || ($info['mime'] ?? null) !== $mime) {
            throw new RbacDenied(422, 'image_invalid', __('accounts.image_invalid'));
        }
        [$w, $h] = $info;
        if ($w > self::MAX_DIM || $h > self::MAX_DIM) {
            throw new RbacDenied(422, 'image_too_large', __('accounts.image_too_large'));
        }
        if ($w < self::MIN_DIM || $h < self::MIN_DIM) {
            throw new RbacDenied(422, 'image_invalid', __('accounts.image_invalid'));
        }
        $src = @(self::MIMES[$mime])($real);
        if (! $src) {
            throw new RbacDenied(422, 'image_invalid', __('accounts.image_invalid'));
        }
        // Centre-crop to a square and scale to SIZE x SIZE on a white background.
        $side = min($w, $h);
        $dst = imagecreatetruecolor(self::SIZE, self::SIZE);
        imagefill($dst, 0, 0, imagecolorallocate($dst, 255, 255, 255));
        imagecopyresampled($dst, $src, 0, 0, intdiv($w - $side, 2), intdiv($h - $side, 2), self::SIZE, self::SIZE, $side, $side);
        ob_start();
        imagejpeg($dst, null, 85);
        $bytes = (string) ob_get_clean();
        unset($src, $dst);

        $relative = 'avatars/'.(int) $employee->company_id.'/'.Str::uuid()->toString().'.jpg';
        $this->disk()->put($relative, $bytes);

        return $relative;
    }

    public function delete(?string $relative): void
    {
        if ($relative && str_starts_with($relative, 'avatars/')) {
            $this->disk()->delete($relative);
        }
    }
}
