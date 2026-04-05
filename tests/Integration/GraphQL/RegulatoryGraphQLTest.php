<?php

declare(strict_types=1);

use App\Domain\Regulatory\Models\RegulatoryReport;
use App\Models\User;

describe('GraphQL Regulatory API', function () {
    it('returns unauthorized without authentication', function () {
        $response = $this->postJson('/graphql', [
            'query' => '{ regulatoryReport(id: "test-uuid") { id status } }',
        ]);

        $response->assertOk();
        expect($response->json())->toHaveKey('errors');
    });

    it('queries regulatory report by id with authentication', function () {
        $user = User::factory()->create();
        $report = RegulatoryReport::create([
            'report_type'            => 'SAR',
            'jurisdiction'           => 'US',
            'status'                 => 'draft',
            'priority'               => 4,
            'is_mandatory'           => true,
            'reporting_period_start' => '2025-01-01',
            'reporting_period_end'   => '2025-03-31',
            'due_date'               => now()->addDays(30),
        ]);

        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/graphql', [
                'query' => '
                    query ($id: ID!) {
                        regulatoryReport(id: $id) {
                            id
                            report_type
                            jurisdiction
                            status
                            is_mandatory
                        }
                    }
                ',
                'variables' => ['id' => $report->id],
            ]);

        $response->assertOk();
        $data = $response->json('data.regulatoryReport');
        expect($data['report_type'])->toBe('SAR');
        expect($data['jurisdiction'])->toBe('US');
        expect($data['status'])->toBe('draft');
    });

    it('paginates regulatory reports', function () {
        $user = User::factory()->create();
        for ($i = 0; $i < 3; $i++) {
            RegulatoryReport::create([
                'report_type'            => 'CTR',
                'jurisdiction'           => 'EU',
                'status'                 => 'draft',
                'priority'               => 3,
                'is_mandatory'           => true,
                'reporting_period_start' => '2025-01-01',
                'reporting_period_end'   => '2025-03-31',
                'due_date'               => now()->addDays(60 + $i),
            ]);
        }

        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/graphql', [
                'query' => '
                    {
                        regulatoryReports(first: 10, page: 1) {
                            data {
                                id
                                report_type
                                jurisdiction
                                status
                            }
                            paginatorInfo {
                                total
                            }
                        }
                    }
                ',
            ]);

        $response->assertOk();
        $data = $response->json('data.regulatoryReports');
        expect($data['data'])->toBeArray();
        expect($data['paginatorInfo']['total'])->toBeGreaterThanOrEqual(3);
    });

    it('submits a regulatory report via mutation', function () {
        $user = User::factory()->create();

        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/graphql', [
                'query' => '
                    mutation ($input: SubmitReportInput!) {
                        submitReport(input: $input) {
                            id
                            report_type
                            jurisdiction
                            status
                        }
                    }
                ',
                'variables' => [
                    'input' => [
                        'report_type'            => 'AML',
                        'jurisdiction'           => 'UK',
                        'reporting_period_start' => '2025-04-01',
                        'reporting_period_end'   => '2025-06-30',
                    ],
                ],
            ]);

        $response->assertOk();
        $json = $response->json();
        expect($json)->not->toHaveKey('errors');
        $data = $response->json('data.submitReport');
        expect($data['report_type'])->toBe('AML');
        expect($data['jurisdiction'])->toBe('UK');
    });
});
