<?php

namespace App\Services;

use App\Models\InstanceSettings;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * Stores the instance logo on the persisted images disk without re-encoding it,
 * so transparent PNGs and SVGs keep their quality.
 */
class BrandingLogoStorageService
{
    public const DIRECTORY = 'branding';

    /** @var array<string, string> */
    public const CONTENT_TYPES = [
        'png' => 'image/png',
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'webp' => 'image/webp',
        'svg' => 'image/svg+xml',
    ];

    public function store(InstanceSettings $settings, UploadedFile $upload): void
    {
        $extension = $this->extension($upload);
        $path = self::DIRECTORY."/logo.{$extension}";

        if (! $this->disk()->put($path, file_get_contents($upload->getRealPath()))) {
            throw new RuntimeException('Unable to store the logo.');
        }

        $oldPath = $settings->brand_logo_path;
        $settings->update(['brand_logo_path' => $path]);

        if ($oldPath && $oldPath !== $path) {
            $this->disk()->delete($oldPath);
        }
    }

    public function delete(InstanceSettings $settings): void
    {
        if ($settings->brand_logo_path) {
            $this->disk()->delete($settings->brand_logo_path);
        }

        $settings->update(['brand_logo_path' => null]);
    }

    /**
     * @return array{contents: string, content_type: string}|null
     */
    public function read(InstanceSettings $settings): ?array
    {
        $path = $settings->brand_logo_path;
        if (! $path || ! str_starts_with($path, self::DIRECTORY.'/') || ! $this->disk()->exists($path)) {
            return null;
        }

        $contentType = self::CONTENT_TYPES[strtolower(pathinfo($path, PATHINFO_EXTENSION))] ?? null;
        if ($contentType === null) {
            return null;
        }

        return ['contents' => $this->disk()->get($path), 'content_type' => $contentType];
    }

    /**
     * Reject SVG files with scripts, event handlers or javascript: URLs.
     */
    public static function isSafeSvg(string $contents): bool
    {
        return ! preg_match('/<script|\bon[a-z]+\s*=|javascript:|<foreignObject/i', $contents);
    }

    protected function extension(UploadedFile $upload): string
    {
        $extension = strtolower((string) ($upload->guessExtension() ?: $upload->getClientOriginalExtension()));
        if ($extension === 'jpeg') {
            return 'jpg';
        }

        if (! array_key_exists($extension, self::CONTENT_TYPES)) {
            throw new RuntimeException('Unsupported logo format.');
        }

        return $extension;
    }

    protected function disk(): FilesystemAdapter
    {
        return Storage::disk('images');
    }
}
