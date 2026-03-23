<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\TrustCert;

use App\Domain\TrustCert\Enums\TrustLevel;
use App\Domain\TrustCert\Services\CertificateAuthorityService;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use OpenApi\Attributes as OA;

class CertificateApplicationController extends Controller
{
    public function __construct(
        private readonly CertificateAuthorityService $certificateAuthority,
    ) {
    }

    /**
     * Create a new certificate application.
     */
    #[OA\Post(
        path: '/api/v1/trustcert/applications',
        operationId: 'trustCertApplicationCreate',
        summary: 'Create a new certificate application',
        description: 'Creates a new trust certificate application for the authenticated user. Only one active application is allowed at a time.',
        tags: ['TrustCert'],
        security: [['sanctum' => []]],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(required: ['target_level'], properties: [
        new OA\Property(property: 'target_level', type: 'string', enum: ['basic', 'verified', 'high', 'ultimate'], example: 'verified', description: 'The target trust level to apply for'),
        ]))
    )]
    #[OA\Response(
        response: 201,
        description: 'Application created successfully',
        content: new OA\JsonContent(properties: [
        new OA\Property(property: 'success', type: 'boolean', example: true),
        new OA\Property(property: 'data', type: 'object', properties: [
        new OA\Property(property: 'id', type: 'string', example: 'app_abc123def456'),
        new OA\Property(property: 'user_id', type: 'integer', example: 1),
        new OA\Property(property: 'target_level', type: 'string', example: 'verified'),
        new OA\Property(property: 'status', type: 'string', example: 'draft'),
        new OA\Property(property: 'requirements', type: 'array', items: new OA\Items(type: 'string')),
        new OA\Property(property: 'documents', type: 'array', items: new OA\Items(type: 'object')),
        new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
        new OA\Property(property: 'updated_at', type: 'string', format: 'date-time'),
        new OA\Property(property: 'submitted_at', type: 'string', format: 'date-time', nullable: true),
        ]),
        ])
    )]
    #[OA\Response(
        response: 409,
        description: 'An active application already exists',
        content: new OA\JsonContent(properties: [
        new OA\Property(property: 'success', type: 'boolean', example: false),
        new OA\Property(property: 'error', type: 'object', properties: [
        new OA\Property(property: 'code', type: 'string', example: 'APPLICATION_EXISTS'),
        new OA\Property(property: 'message', type: 'string', example: 'An active application already exists.'),
        ]),
        ])
    )]
    #[OA\Response(
        response: 401,
        description: 'Unauthorized'
    )]
    #[OA\Response(
        response: 422,
        description: 'Validation error'
    )]
    public function create(Request $request): JsonResponse
    {
        $request->validate([
            'target_level' => ['required', 'string', 'in:basic,verified,high,ultimate'],
        ]);

        $user = $request->user();
        $targetLevel = TrustLevel::from($request->input('target_level'));

        // Check for existing active application
        $existing = $this->getActiveApplication($user->id);
        if ($existing) {
            return response()->json([
                'success' => false,
                'error'   => [
                    'code'    => 'APPLICATION_EXISTS',
                    'message' => 'An active application already exists.',
                ],
            ], 409);
        }

        // Pre-populate required documents based on target level
        $requiredDocuments = array_map(
            fn (string $type) => ['type' => $type, 'status' => 'pending'],
            $targetLevel->documents(),
        );

        $application = [
            'id'                => 'app_' . Str::random(20),
            'user_id'           => $user->id,
            'target_level'      => $targetLevel->value,
            'status'            => 'draft',
            'requirements'      => $targetLevel->requirements(),
            'requiredDocuments' => $requiredDocuments,
            'documents'         => $requiredDocuments,
            'created_at'        => now()->toIso8601String(),
            'updated_at'        => now()->toIso8601String(),
            'submitted_at'      => null,
        ];

        $this->storeApplication($user->id, $application);

        return response()->json([
            'success' => true,
            'data'    => $application,
        ], 201);
    }

    /**
     * Get a specific application by ID.
     */
    #[OA\Get(
        path: '/api/v1/trustcert/applications/{id}',
        operationId: 'trustCertApplicationShow',
        summary: 'Get a specific certificate application',
        description: 'Retrieves a specific trust certificate application by its ID for the authenticated user.',
        tags: ['TrustCert'],
        security: [['sanctum' => []]],
        parameters: [
        new OA\Parameter(name: 'id', in: 'path', required: true, description: 'The application ID', schema: new OA\Schema(type: 'string', example: 'app_abc123def456')),
        ]
    )]
    #[OA\Response(
        response: 200,
        description: 'Application retrieved successfully',
        content: new OA\JsonContent(properties: [
        new OA\Property(property: 'success', type: 'boolean', example: true),
        new OA\Property(property: 'data', type: 'object', properties: [
        new OA\Property(property: 'id', type: 'string', example: 'app_abc123def456'),
        new OA\Property(property: 'user_id', type: 'integer', example: 1),
        new OA\Property(property: 'target_level', type: 'string', example: 'verified'),
        new OA\Property(property: 'status', type: 'string', example: 'draft'),
        new OA\Property(property: 'requirements', type: 'array', items: new OA\Items(type: 'string')),
        new OA\Property(property: 'documents', type: 'array', items: new OA\Items(type: 'object')),
        new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
        new OA\Property(property: 'updated_at', type: 'string', format: 'date-time'),
        new OA\Property(property: 'submitted_at', type: 'string', format: 'date-time', nullable: true),
        ]),
        ])
    )]
    #[OA\Response(
        response: 404,
        description: 'Application not found',
        content: new OA\JsonContent(properties: [
        new OA\Property(property: 'success', type: 'boolean', example: false),
        new OA\Property(property: 'error', type: 'object', properties: [
        new OA\Property(property: 'code', type: 'string', example: 'APPLICATION_NOT_FOUND'),
        new OA\Property(property: 'message', type: 'string', example: 'Application not found.'),
        ]),
        ])
    )]
    #[OA\Response(
        response: 401,
        description: 'Unauthorized'
    )]
    public function show(string $id, Request $request): JsonResponse
    {
        $user = $request->user();
        $application = $this->findApplication($user->id, $id);

        if (! $application) {
            return response()->json([
                'success' => false,
                'error'   => [
                    'code'    => 'APPLICATION_NOT_FOUND',
                    'message' => 'Application not found.',
                ],
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data'    => $application,
        ]);
    }

    /**
     * Get the user's current active application.
     */
    #[OA\Get(
        path: '/api/v1/trustcert/applications/current',
        operationId: 'trustCertApplicationCurrent',
        summary: 'Get current active certificate application',
        description: 'Returns the authenticated user\'s current active trust certificate application, or null if none exists.',
        tags: ['TrustCert'],
        security: [['sanctum' => []]]
    )]
    #[OA\Response(
        response: 200,
        description: 'Success',
        content: new OA\JsonContent(properties: [
        new OA\Property(property: 'success', type: 'boolean', example: true),
        new OA\Property(property: 'data', type: 'object', nullable: true, properties: [
        new OA\Property(property: 'id', type: 'string', example: 'app_abc123def456'),
        new OA\Property(property: 'user_id', type: 'integer', example: 1),
        new OA\Property(property: 'target_level', type: 'string', example: 'verified'),
        new OA\Property(property: 'status', type: 'string', example: 'draft'),
        new OA\Property(property: 'requirements', type: 'array', items: new OA\Items(type: 'string')),
        new OA\Property(property: 'documents', type: 'array', items: new OA\Items(type: 'object')),
        new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
        new OA\Property(property: 'updated_at', type: 'string', format: 'date-time'),
        new OA\Property(property: 'submitted_at', type: 'string', format: 'date-time', nullable: true),
        ]),
        ])
    )]
    #[OA\Response(
        response: 401,
        description: 'Unauthorized'
    )]
    public function currentApplication(Request $request): JsonResponse
    {
        $user = $request->user();
        $application = $this->getActiveApplication($user->id);

        return response()->json([
            'success' => true,
            'data'    => $application,
        ]);
    }

    /**
     * Upload documents for a certificate application.
     */
    #[OA\Post(
        path: '/api/v1/trustcert/applications/{id}/documents',
        operationId: 'trustCertApplicationUploadDocuments',
        summary: 'Upload documents for a certificate application',
        description: 'Uploads a document to the specified certificate application. The application must be in draft status.',
        tags: ['TrustCert'],
        security: [['sanctum' => []]],
        parameters: [
        new OA\Parameter(name: 'id', in: 'path', required: true, description: 'The application ID', schema: new OA\Schema(type: 'string', example: 'app_abc123def456')),
        ],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(required: ['document_type', 'file_name'], properties: [
        new OA\Property(property: 'document_type', type: 'string', enum: ['identity', 'address', 'kyc', 'audit'], example: 'identity', description: 'The type of document being uploaded'),
        new OA\Property(property: 'file_name', type: 'string', example: 'passport_scan.pdf', description: 'The name of the uploaded file'),
        ]))
    )]
    #[OA\Response(
        response: 201,
        description: 'Document uploaded successfully',
        content: new OA\JsonContent(properties: [
        new OA\Property(property: 'success', type: 'boolean', example: true),
        new OA\Property(property: 'data', type: 'object', properties: [
        new OA\Property(property: 'id', type: 'string', example: 'doc_abc123def456'),
        new OA\Property(property: 'document_type', type: 'string', example: 'identity'),
        new OA\Property(property: 'file_name', type: 'string', example: 'passport_scan.pdf'),
        new OA\Property(property: 'uploaded_at', type: 'string', format: 'date-time'),
        ]),
        ])
    )]
    #[OA\Response(
        response: 404,
        description: 'Application not found',
        content: new OA\JsonContent(properties: [
        new OA\Property(property: 'success', type: 'boolean', example: false),
        new OA\Property(property: 'error', type: 'object', properties: [
        new OA\Property(property: 'code', type: 'string', example: 'APPLICATION_NOT_FOUND'),
        new OA\Property(property: 'message', type: 'string', example: 'Application not found.'),
        ]),
        ])
    )]
    #[OA\Response(
        response: 422,
        description: 'Application is not editable or validation error',
        content: new OA\JsonContent(properties: [
        new OA\Property(property: 'success', type: 'boolean', example: false),
        new OA\Property(property: 'error', type: 'object', properties: [
        new OA\Property(property: 'code', type: 'string', example: 'APPLICATION_NOT_EDITABLE'),
        new OA\Property(property: 'message', type: 'string', example: 'Application is not in a draft state.'),
        ]),
        ])
    )]
    #[OA\Response(
        response: 401,
        description: 'Unauthorized'
    )]
    public function uploadDocuments(string $id, Request $request): JsonResponse
    {
        $request->validate([
            'document_type' => ['required', 'string', 'in:identity,address,kyc,audit'],
            'file_name'     => ['required', 'string'],
        ]);

        $user = $request->user();
        $application = $this->findApplication($user->id, $id);

        if (! $application) {
            return response()->json([
                'success' => false,
                'error'   => [
                    'code'    => 'APPLICATION_NOT_FOUND',
                    'message' => 'Application not found.',
                ],
            ], 404);
        }

        if ($application['status'] !== 'draft') {
            return response()->json([
                'success' => false,
                'error'   => [
                    'code'    => 'APPLICATION_NOT_EDITABLE',
                    'message' => 'Application is not in a draft state.',
                ],
            ], 422);
        }

        $document = [
            'id'            => 'doc_' . Str::random(16),
            'document_type' => $request->input('document_type'),
            'file_name'     => $request->input('file_name'),
            'uploaded_at'   => now()->toIso8601String(),
        ];

        $application['documents'][] = $document;
        $application['updated_at'] = now()->toIso8601String();
        $this->storeApplication($user->id, $application);

        return response()->json([
            'success' => true,
            'data'    => $document,
        ], 201);
    }

    /**
     * Submit an application for review.
     */
    #[OA\Post(
        path: '/api/v1/trustcert/applications/{id}/submit',
        operationId: 'trustCertApplicationSubmit',
        summary: 'Submit a certificate application for review',
        description: 'Submits a draft certificate application for review. Only applications in draft status can be submitted.',
        tags: ['TrustCert'],
        security: [['sanctum' => []]],
        parameters: [
        new OA\Parameter(name: 'id', in: 'path', required: true, description: 'The application ID', schema: new OA\Schema(type: 'string', example: 'app_abc123def456')),
        ]
    )]
    #[OA\Response(
        response: 200,
        description: 'Application submitted successfully',
        content: new OA\JsonContent(properties: [
        new OA\Property(property: 'success', type: 'boolean', example: true),
        new OA\Property(property: 'data', type: 'object', properties: [
        new OA\Property(property: 'id', type: 'string', example: 'app_abc123def456'),
        new OA\Property(property: 'user_id', type: 'integer', example: 1),
        new OA\Property(property: 'target_level', type: 'string', example: 'verified'),
        new OA\Property(property: 'status', type: 'string', example: 'submitted'),
        new OA\Property(property: 'requirements', type: 'array', items: new OA\Items(type: 'string')),
        new OA\Property(property: 'documents', type: 'array', items: new OA\Items(type: 'object')),
        new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
        new OA\Property(property: 'updated_at', type: 'string', format: 'date-time'),
        new OA\Property(property: 'submitted_at', type: 'string', format: 'date-time'),
        ]),
        ])
    )]
    #[OA\Response(
        response: 404,
        description: 'Application not found',
        content: new OA\JsonContent(properties: [
        new OA\Property(property: 'success', type: 'boolean', example: false),
        new OA\Property(property: 'error', type: 'object', properties: [
        new OA\Property(property: 'code', type: 'string', example: 'APPLICATION_NOT_FOUND'),
        new OA\Property(property: 'message', type: 'string', example: 'Application not found.'),
        ]),
        ])
    )]
    #[OA\Response(
        response: 422,
        description: 'Application is not submittable',
        content: new OA\JsonContent(properties: [
        new OA\Property(property: 'success', type: 'boolean', example: false),
        new OA\Property(property: 'error', type: 'object', properties: [
        new OA\Property(property: 'code', type: 'string', example: 'APPLICATION_NOT_SUBMITTABLE'),
        new OA\Property(property: 'message', type: 'string', example: 'Only draft applications can be submitted.'),
        ]),
        ])
    )]
    #[OA\Response(
        response: 401,
        description: 'Unauthorized'
    )]
    public function submit(string $id, Request $request): JsonResponse
    {
        $user = $request->user();
        $application = $this->findApplication($user->id, $id);

        if (! $application) {
            return response()->json([
                'success' => false,
                'error'   => [
                    'code'    => 'APPLICATION_NOT_FOUND',
                    'message' => 'Application not found.',
                ],
            ], 404);
        }

        if ($application['status'] !== 'draft') {
            return response()->json([
                'success' => false,
                'error'   => [
                    'code'    => 'APPLICATION_NOT_SUBMITTABLE',
                    'message' => 'Only draft applications can be submitted.',
                ],
            ], 422);
        }

        $application['status'] = 'submitted';
        $application['submitted_at'] = now()->toIso8601String();
        $application['updated_at'] = now()->toIso8601String();
        $this->storeApplication($user->id, $application);

        return response()->json([
            'success' => true,
            'data'    => $application,
        ]);
    }

    /**
     * Cancel a pending application.
     */
    #[OA\Post(
        path: '/api/v1/trustcert/applications/{id}/cancel',
        operationId: 'trustCertApplicationCancel',
        summary: 'Cancel a certificate application',
        description: 'Cancels a pending certificate application. Applications that are already approved or cancelled cannot be cancelled.',
        tags: ['TrustCert'],
        security: [['sanctum' => []]],
        parameters: [
        new OA\Parameter(name: 'id', in: 'path', required: true, description: 'The application ID', schema: new OA\Schema(type: 'string', example: 'app_abc123def456')),
        ]
    )]
    #[OA\Response(
        response: 200,
        description: 'Application cancelled successfully',
        content: new OA\JsonContent(properties: [
        new OA\Property(property: 'success', type: 'boolean', example: true),
        new OA\Property(property: 'data', type: 'object', properties: [
        new OA\Property(property: 'id', type: 'string', example: 'app_abc123def456'),
        new OA\Property(property: 'user_id', type: 'integer', example: 1),
        new OA\Property(property: 'target_level', type: 'string', example: 'verified'),
        new OA\Property(property: 'status', type: 'string', example: 'cancelled'),
        new OA\Property(property: 'requirements', type: 'array', items: new OA\Items(type: 'string')),
        new OA\Property(property: 'documents', type: 'array', items: new OA\Items(type: 'object')),
        new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
        new OA\Property(property: 'updated_at', type: 'string', format: 'date-time'),
        new OA\Property(property: 'submitted_at', type: 'string', format: 'date-time', nullable: true),
        ]),
        ])
    )]
    #[OA\Response(
        response: 404,
        description: 'Application not found',
        content: new OA\JsonContent(properties: [
        new OA\Property(property: 'success', type: 'boolean', example: false),
        new OA\Property(property: 'error', type: 'object', properties: [
        new OA\Property(property: 'code', type: 'string', example: 'APPLICATION_NOT_FOUND'),
        new OA\Property(property: 'message', type: 'string', example: 'Application not found.'),
        ]),
        ])
    )]
    #[OA\Response(
        response: 422,
        description: 'Application cannot be cancelled',
        content: new OA\JsonContent(properties: [
        new OA\Property(property: 'success', type: 'boolean', example: false),
        new OA\Property(property: 'error', type: 'object', properties: [
        new OA\Property(property: 'code', type: 'string', example: 'APPLICATION_NOT_CANCELLABLE'),
        new OA\Property(property: 'message', type: 'string', example: 'This application cannot be cancelled.'),
        ]),
        ])
    )]
    #[OA\Response(
        response: 401,
        description: 'Unauthorized'
    )]
    public function cancel(string $id, Request $request): JsonResponse
    {
        $user = $request->user();
        $application = $this->findApplication($user->id, $id);

        if (! $application) {
            return response()->json([
                'success' => false,
                'error'   => [
                    'code'    => 'APPLICATION_NOT_FOUND',
                    'message' => 'Application not found.',
                ],
            ], 404);
        }

        if (in_array($application['status'], ['approved', 'cancelled'], true)) {
            return response()->json([
                'success' => false,
                'error'   => [
                    'code'    => 'APPLICATION_NOT_CANCELLABLE',
                    'message' => 'This application cannot be cancelled.',
                ],
            ], 422);
        }

        $application['status'] = 'cancelled';
        $application['updated_at'] = now()->toIso8601String();
        $this->storeApplication($user->id, $application);

        return response()->json([
            'success' => true,
            'data'    => $application,
        ]);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function getActiveApplication(int $userId): ?array
    {
        $application = Cache::get("trustcert_application:{$userId}");

        if (! $application || in_array($application['status'], ['approved', 'cancelled'], true)) {
            return null;
        }

        // Normalize status for mobile compatibility:
        // Backend uses 'draft'/'submitted', mobile expects 'pending'/'in_review'
        $application['status'] = match ($application['status']) {
            'draft'     => 'pending',
            'submitted' => 'in_review',
            default     => $application['status'],
        };

        return $application;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function findApplication(int $userId, string $id): ?array
    {
        $application = Cache::get("trustcert_application:{$userId}");

        if (! $application || $application['id'] !== $id) {
            return null;
        }

        return $application;
    }

    /**
     * @param array<string, mixed> $application
     */
    private function storeApplication(int $userId, array $application): void
    {
        Cache::put("trustcert_application:{$userId}", $application, now()->addDays(30));
    }
}
