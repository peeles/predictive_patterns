<?php

namespace App\Http\Controllers\Api\v1;

use App\Http\Controllers\Controller;
use App\Http\Requests\NaturalLanguageQueryRequest;
use App\Services\NaturalLanguageQueryService;
use Illuminate\Http\JsonResponse;

class NlqController extends BaseController
{
    public function __construct(private readonly NaturalLanguageQueryService $nlqService)
    {
    }

    public function __invoke(NaturalLanguageQueryRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $question = (string) $validated['question'];
        $answer = $this->nlqService->answer($question);

        return $this->successResponse($answer);
    }
}
