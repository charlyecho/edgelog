<?php

namespace App\Models\Concerns;

use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * An image a model is shown with (icon_path, icon_changed_at), kept on the
 * local disk under the model's uuid and deleted along with it.
 */
trait HasIcon
{
    /**
     * The image types an icon can be, by the type read from its bytes.
     *
     * @var array<string, string>
     */
    public const array ICON_TYPES = [
        'image/png' => 'png',
        'image/jpeg' => 'jpg',
        'image/gif' => 'gif',
        'image/webp' => 'webp',
        'image/svg+xml' => 'svg',
        'image/vnd.microsoft.icon' => 'ico',
        'image/x-icon' => 'ico',
    ];

    /**
     * The largest icon kept, in bytes.
     */
    public const int ICON_MAX_BYTES = 512 * 1024;

    /**
     * The folder of the local disk the model's icons are kept in.
     */
    abstract protected function iconFolder(): string;

    /**
     * Delete the icon's file along with the model.
     */
    protected static function bootHasIcon(): void
    {
        static::deleted(function (self $model) {
            if ($model->icon_path !== null) {
                Storage::disk('local')->delete($model->icon_path);
            }
        });
    }

    /**
     * Keep an image as the icon, in place of the previous one, with the
     * attributes given. Returns false, keeping the previous icon, when the
     * bytes are not an image of a known type.
     *
     * @param  array<string, mixed>  $attributes
     */
    protected function putIcon(string $contents, array $attributes = []): bool
    {
        $extension = self::iconExtension($contents);

        if ($extension === null) {
            return false;
        }

        $path = "{$this->iconFolder()}/{$this->uuid}.{$extension}";
        Storage::disk('local')->put($path, $contents);

        if ($this->icon_path !== null && $this->icon_path !== $path) {
            Storage::disk('local')->delete($this->icon_path);
        }

        $this->forceFill(['icon_path' => $path, 'icon_changed_at' => now(), ...$attributes])->save();

        return true;
    }

    /**
     * Delete the icon, with the attributes given: the model is shown with its initial again.
     *
     * @param  array<string, mixed>  $attributes
     */
    protected function removeIcon(array $attributes = []): void
    {
        if ($this->icon_path !== null) {
            Storage::disk('local')->delete($this->icon_path);
        }

        $this->forceFill(['icon_path' => null, 'icon_changed_at' => now(), ...$attributes])->save();
    }

    /**
     * The icon, as an image that can run nothing; 404 when there is none.
     */
    public function iconResponse(): StreamedResponse
    {
        abort_if($this->icon_path === null || ! Storage::disk('local')->exists($this->icon_path), 404);

        return Storage::disk('local')->response($this->icon_path, null, [
            'Content-Type' => array_search(pathinfo($this->icon_path, PATHINFO_EXTENSION), self::ICON_TYPES, true),
            'Cache-Control' => 'private, max-age=31536000, immutable',
            'Content-Security-Policy' => "default-src 'none'; style-src 'unsafe-inline'; sandbox",
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /**
     * Get the extension of an icon's image type, read from its bytes, or null when it is not one.
     */
    public static function iconExtension(string $contents): ?string
    {
        if ($contents === '' || strlen($contents) > self::ICON_MAX_BYTES) {
            return null;
        }

        $type = (new \finfo(FILEINFO_MIME_TYPE))->buffer($contents);

        return self::ICON_TYPES[$type] ?? null;
    }
}
