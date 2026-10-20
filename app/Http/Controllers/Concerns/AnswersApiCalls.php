<?php

namespace App\Http\Controllers\Concerns;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;

/**
 * Lets a web controller action serve the API as well (spec 011): called
 * under /api/v1 or asking for JSON it answers with data instead of a
 * redirect, so both run the same code.
 */
trait AnswersApiCalls
{
    protected function wantsApiAnswer(): bool
    {
        $request = request();

        return $request->is('api/*') || $request->wantsJson();
    }

    /**
     * Back to the page with a message on the web; the data (or 204 without)
     * over the API.
     */
    protected function answer(string $message, mixed $data = null, int $status = 200): RedirectResponse|JsonResponse
    {
        if (! $this->wantsApiAnswer()) {
            return back()->with('success', $message);
        }

        return $data === null
            ? response()->json(null, 204)
            : response()->json(['data' => $data, 'message' => $message], $status);
    }
}
