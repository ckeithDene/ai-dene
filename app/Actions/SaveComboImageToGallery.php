<?php

namespace App\Actions;

use App\Models\ComboImage;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

class SaveComboImageToGallery
{
    /**
     * Keep a downloaded combo image in the gallery.
     *
     * The preview file can be replaced by the next image. The gallery copy stays.
     */
    public function remember(string $source, User $user): ComboImage
    {
        if (! Str::isUuid($source)) {
            throw new RuntimeException('That combo image could not be saved.');
        }

        $existing = ComboImage::query()->where('source', $source)->first();

        if ($existing instanceof ComboImage) {
            return $existing;
        }

        $preview = 'combo-images/'.$source.'.png';

        if (! Storage::disk('local')->exists($preview)) {
            throw new RuntimeException('That combo image could not be saved.');
        }

        $uuid = (string) Str::uuid();
        $stored = Storage::disk('local')->copy($preview, 'combo-gallery/'.$uuid.'.png');

        if ($stored !== true) {
            throw new RuntimeException('That combo image could not be saved.');
        }

        try {
            return ComboImage::query()->create([
                'user_id' => $user->id,
                'uuid' => $uuid,
                'source' => $source,
            ]);
        } catch (UniqueConstraintViolationException) {
            Storage::disk('local')->delete('combo-gallery/'.$uuid.'.png');

            return ComboImage::query()->where('source', $source)->firstOrFail();
        }
    }
}
