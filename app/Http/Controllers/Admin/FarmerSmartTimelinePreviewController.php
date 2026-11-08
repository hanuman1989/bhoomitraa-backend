<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\PreviewFarmerSmartTimelineRequest;
use App\Http\Resources\Admin\FarmerSmartTimelinePreviewResource;
use App\Services\FarmerSmartTimelineGenerator;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use PDOException;

class FarmerSmartTimelinePreviewController extends Controller
{
    /**
     * Handle the incoming request.
     */
    public function __invoke(PreviewFarmerSmartTimelineRequest $request, FarmerSmartTimelineGenerator $generator): JsonResponse
    {
        try {
            $preview = $generator->generate($request->validated());
        } catch (QueryException|PDOException $exception) {
            return $this->databaseFailure($exception, 'Unable to generate the timeline preview. Please try again later.');
        }

        return response()->json([
            'status' => true,
            'data' => new FarmerSmartTimelinePreviewResource($preview),
            'pagination' => null,
            'message' => 'Timeline preview generated successfully.',
        ]);
    }
}
