<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\OfficeDocument;
use Illuminate\Http\Request;

/**
 * POST /api/documents/pdf
 *
 * Turns a Word / PowerPoint file the user picked on their own computer into a PDF, so the Quiz
 * and Translate tools can read old .doc / .ppt files the same way they read a PDF. Nothing is
 * stored: the upload is converted and deleted straight away.
 */
class DocumentConversionController extends Controller
{
    public function toPdf(Request $request)
    {
        $request->validate([
            'file' => [
                'bail',
                'required',
                'file',
                'max:20480',
                function (string $attribute, mixed $value, \Closure $fail) {
                    if (!OfficeDocument::looksLikeOffice($value)) {
                        $fail('The file must be a Word (.doc, .docx) or PowerPoint (.ppt, .pptx) file.');
                    }
                },
            ],
        ]);

        $file = $request->file('file');

        $pdf = OfficeDocument::convert(
            $file->getRealPath(),
            OfficeDocument::extension($file->getClientOriginalName())
        );

        return response()->file($pdf, [
            'Content-Type' => 'application/pdf',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
        ])->deleteFileAfterSend(true);
    }
}
