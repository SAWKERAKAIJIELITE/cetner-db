<?php

namespace App\Modules\Documents\Support;

use finfo;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Where the documents' content is kept (D-049): a private disk, one file per upload at
 * `documents/{employee}/{upload}.{ext}`. The name is the upload's id, never the name it came with.
 */
final class DocumentStorage
{
    public static function disk(): string
    {
        return (string) config('hr.documents_disk', 'documents');
    }

    /** The type the content says it is (libmagic), not the one the browser sent or the name suggests. */
    public static function sniff(UploadedFile $file): string
    {
        $mime = (new finfo(FILEINFO_MIME_TYPE))->file($file->getRealPath());

        return is_string($mime) ? $mime : 'application/octet-stream';
    }

    public static function put(string $employeeId, string $documentId, string $extension, UploadedFile $file): string
    {
        $path = "documents/{$employeeId}/{$documentId}.{$extension}";
        $stream = fopen($file->getRealPath(), 'rb');

        try {
            Storage::disk(self::disk())->writeStream($path, $stream);
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }

        return $path;
    }

    /** Best effort: a file already gone is not an error (the row says what happened). */
    public static function delete(string $disk, string $path): void
    {
        try {
            Storage::disk($disk)->delete($path);
        } catch (Throwable $e) {
            report($e);
        }
    }

    public static function exists(string $disk, string $path): bool
    {
        return Storage::disk($disk)->exists($path);
    }
}
