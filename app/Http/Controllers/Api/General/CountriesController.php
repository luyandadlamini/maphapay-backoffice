<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\General;

use App\Http\Controllers\Controller;
use App\Models\Country;
use Illuminate\Http\JsonResponse;
use OpenApi\Attributes as OA;

class CountriesController extends Controller
{
    #[OA\Get(
        path: '/api/countries',
        summary: 'Get list of countries',
        description: 'Returns all active countries with dial codes and currency info',
        operationId: 'getCountries',
        tags: ['General']
    )]
    #[OA\Response(
        response: 200,
        description: 'Countries retrieved successfully',
        content: new OA\JsonContent(properties: [
            new OA\Property(property: 'success', type: 'boolean', example: true),
            new OA\Property(property: 'data', type: 'array', items: new OA\Items(type: 'object', properties: [
                new OA\Property(property: 'id', type: 'integer'),
                new OA\Property(property: 'name', type: 'string'),
                new OA\Property(property: 'code', type: 'string'),
                new OA\Property(property: 'dial_code', type: 'string'),
                new OA\Property(property: 'currency_code', type: 'string', nullable: true),
                new OA\Property(property: 'currency_name', type: 'string', nullable: true),
                new OA\Property(property: 'is_active', type: 'boolean'),
            ])),
        ])
    )]
    public function index(): JsonResponse
    {
        $countries = Country::where('is_active', true)
            ->orderBy('name')
            ->get();

        return response()->json([
            'success' => true,
            'data'    => $countries,
        ]);
    }
}
