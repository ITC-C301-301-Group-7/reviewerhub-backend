<?php

namespace App\Support;

use App\Models\LearningResource;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * Word (.doc, .docx) and PowerPoint (.ppt, .pptx) support.
 *
 * Browsers cannot draw these files, so for Preview they are converted to PDF once with
 * LibreOffice (headless) and the PDF is cached. After that they behave exactly like an uploaded
 * PDF: same preview, same tier page limit. The original file is what Download serves.
 *
 * Needs LibreOffice on the server. Set LIBREOFFICE_PATH in .env if it is not found
 * automatically. Without it, uploads and downloads still work; only Preview/Quiz/Translate of
 * Word/PowerPoint files report "preview not available".
 */
class OfficeDocument
{
    /** Extension => the mime type we store and serve for it. */
    public const MIMES = [
        'doc' => 'application/msword',
        'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'ppt' => 'application/vnd.ms-powerpoint',
        'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
    ];

    public static function extension(?string $filename): string
    {
        return strtolower(pathinfo((string) $filename, PATHINFO_EXTENSION));
    }

    public static function isOffice(?string $filename): bool
    {
        return array_key_exists(self::extension($filename), self::MIMES);
    }

    /** The canonical mime type for an Office filename, or null for any other file. */
    public static function mimeFor(?string $filename): ?string
    {
        return self::MIMES[self::extension($filename)] ?? null;
    }

    /**
     * Is this upload really a Word/PowerPoint file? Checked by the file's first bytes, because
     * PHP often reports these as application/zip or application/x-ole-storage instead of the
     * proper type, which made the stock "mimes:" rule reject valid files.
     *
     * docx / pptx are ZIP packages; legacy doc / ppt are OLE compound files (a few .doc files
     * are really RTF).
     */
    public static function looksLikeOffice(UploadedFile $file): bool
    {
        $ext = self::extension($file->getClientOriginalName());
        if (!isset(self::MIMES[$ext])) {
            return false;
        }

        $handle = @fopen($file->getRealPath(), 'rb');
        if (!$handle) {
            return false;
        }
        $head = (string) fread($handle, 8);
        fclose($handle);

        if ($ext === 'docx' || $ext === 'pptx') {
            return str_starts_with($head, "PK\x03\x04");
        }

        return str_starts_with($head, "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1")
            || ($ext === 'doc' && str_starts_with($head, '{\\rtf'));
    }

    /* ------------------------------ Preview cache ------------------------------ */

    private static function cachePath(LearningResource $resource): string
    {
        return "previews/{$resource->id}.pdf";
    }

    /**
     * Absolute path of the PDF version of this reviewer, converting it the first time.
     * Aborts with a readable HTTP error when the file or LibreOffice is unavailable.
     */
    public static function pdfFor(LearningResource $resource): string
    {
        $disk = Storage::disk('local');

        abort_unless(
            $disk->exists($resource->file_path),
            404,
            'The reviewer file could not be found.'
        );

        $cache = self::cachePath($resource);

        // Reuse the converted copy unless the original is newer than it.
        if (
            $disk->exists($cache)
            && $disk->size($cache) > 0
            && $disk->lastModified($cache) >= $disk->lastModified($resource->file_path)
        ) {
            return $disk->path($cache);
        }

        $pdf = self::convert(
            $disk->path($resource->file_path),
            self::extension($resource->original_filename)
        );

        try {
            $disk->makeDirectory('previews');
            File::move($pdf, $disk->path($cache));
        } finally {
            File::delete($pdf);
        }

        return $disk->path($cache);
    }

    /** Remove the cached preview (call when a reviewer is deleted). */
    public static function forget(LearningResource $resource): void
    {
        Storage::disk('local')->delete(self::cachePath($resource));
    }

    /* ------------------------------ Conversion ------------------------------ */

    /**
     * Convert one Word/PowerPoint file to PDF. Returns the path of a temporary PDF that the
     * caller must move or delete. Aborts with an HTTP error if it cannot be converted.
     */
    public static function convert(string $sourcePath, string $extension): string
    {
        $extension = strtolower($extension);
        abort_unless(isset(self::MIMES[$extension]), 415, 'This file type cannot be converted to PDF.');

        $binary = self::binary();
        abort_if(
            $binary === null,
            503,
            'Preview for Word and PowerPoint files is not set up on this server yet. Download the file instead.'
        );

        // LibreOffice cannot run two instances on one profile, so conversions queue up here.
        try {
            return Cache::lock('office-pdf-conversion', 150)->block(
                120,
                fn() => self::runLibreOffice($binary, $sourcePath, $extension)
            );
        } catch (LockTimeoutException) {
            abort(503, 'The server is busy converting other files. Please try again in a moment.');
        }
    }

    private static function runLibreOffice(string $binary, string $sourcePath, string $extension): string
    {
        $profile = storage_path('app/libreoffice-profile');
        $work = storage_path('app/tmp/convert-' . Str::random(16));
        File::ensureDirectoryExists($profile);
        File::ensureDirectoryExists($work);

        try {
            // A fixed, plain name avoids trouble with spaces / unicode in the original filename.
            $input = "{$work}/source.{$extension}";
            File::copy($sourcePath, $input);

            $process = new Process(
                [
                    $binary,
                    '--headless',
                    '--norestore',
                    '--nologo',
                    '--nolockcheck',
                    '-env:UserInstallation=' . self::fileUrl($profile),
                    '--convert-to',
                    'pdf',
                    '--outdir',
                    $work,
                    $input,
                ],
                $work,
                // HOME must be writable for LibreOffice when PHP runs as a service user.
                ['HOME' => $profile],
                null,
                110
            );

            $process->run();

            $output = "{$work}/source.pdf";

            if (!is_file($output) || filesize($output) === 0 || file_get_contents($output, false, null, 0, 4) !== '%PDF') {
                report(new \RuntimeException(
                    'LibreOffice conversion failed: ' . trim($process->getErrorOutput() . ' ' . $process->getOutput())
                ));
                abort(422, 'This file could not be converted for preview. It may be damaged or password-protected. Download it instead.');
            }

            // Hand the result out of the work folder before it is deleted.
            $result = storage_path('app/tmp/' . Str::random(20) . '.pdf');
            File::move($output, $result);

            return $result;
        } catch (\Symfony\Component\Process\Exception\ProcessTimedOutException) {
            abort(422, 'Converting this file took too long. Download it instead.');
        } finally {
            File::deleteDirectory($work);
        }
    }

    /** LibreOffice's command-line program, or null if it cannot be found. */
    private static function binary(): ?string
    {
        $configured = trim((string) config('reviewerhub.office_preview.binary', ''));
        if ($configured !== '') {
            return is_file($configured) ? $configured : null;
        }

        foreach (
            [
                'C:\\Program Files\\LibreOffice\\program\\soffice.exe',
                'C:\\Program Files (x86)\\LibreOffice\\program\\soffice.exe',
                '/Applications/LibreOffice.app/Contents/MacOS/soffice',
            ] as $candidate
        ) {
            if (is_file($candidate)) {
                return $candidate;
            }
        }

        $finder = new ExecutableFinder();

        return $finder->find('soffice') ?? $finder->find('libreoffice');
    }

    /** file:///C:/path or file:///var/path, as LibreOffice's -env:UserInstallation expects. */
    private static function fileUrl(string $path): string
    {
        return 'file:///' . ltrim(str_replace('\\', '/', $path), '/');
    }
}
