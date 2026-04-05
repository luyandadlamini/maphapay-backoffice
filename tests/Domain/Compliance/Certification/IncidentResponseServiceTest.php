<?php

declare(strict_types=1);

use App\Domain\Compliance\Models\SecurityIncident;
use App\Domain\Compliance\Services\Certification\IncidentResponseService;

describe('IncidentResponseService', function () {
    beforeEach(function () {
        $this->service = new IncidentResponseService();
    });

    it('creates a new security incident', function () {
        $incident = $this->service->createIncident([
            'title'       => 'Test Incident',
            'description' => 'A test security incident',
            'severity'    => 'medium',
        ]);

        expect($incident)->toBeInstanceOf(SecurityIncident::class)
            ->and($incident->title)->toBe('Test Incident')
            ->and($incident->severity)->toBe('medium')
            ->and($incident->status)->toBe('open')
            ->and($incident->timeline)->toBeArray()
            ->and($incident->timeline)->not->toBeEmpty();
    });

    it('resolves an incident', function () {
        $incident = $this->service->createIncident([
            'title'       => 'Resolve Test',
            'description' => 'Incident to resolve',
            'severity'    => 'low',
        ]);

        $resolved = $this->service->resolveIncident($incident->id, 'Issue was a false positive');

        expect($resolved->status)->toBe('resolved')
            ->and($resolved->resolution)->toBe('Issue was a false positive')
            ->and($resolved->resolved_at)->not->toBeNull();
    });

    it('updates an incident', function () {
        $incident = $this->service->createIncident([
            'title'       => 'Update Test',
            'description' => 'Original description',
            'severity'    => 'high',
        ]);

        $updated = $this->service->updateIncident($incident->id, [
            'status'      => 'investigating',
            'assigned_to' => 'security-team',
        ]);

        expect($updated->status)->toBe('investigating')
            ->and($updated->assigned_to)->toBe('security-team');
    });

    it('generates incident statistics', function () {
        $this->service->createIncident([
            'title'       => 'Stats Test 1',
            'description' => 'Test',
            'severity'    => 'high',
        ]);
        $this->service->createIncident([
            'title'       => 'Stats Test 2',
            'description' => 'Test',
            'severity'    => 'low',
        ]);

        $stats = $this->service->getIncidentStatistics();

        expect($stats)->toBeArray()
            ->and($stats)->toHaveKey('total_incidents')
            ->and($stats['total_incidents'])->toBeGreaterThanOrEqual(2);
    });

    it('generates postmortem for resolved incident', function () {
        $incident = $this->service->createIncident([
            'title'            => 'Postmortem Test',
            'description'      => 'Incident for postmortem',
            'severity'         => 'critical',
            'affected_systems' => ['api', 'database'],
        ]);
        $this->service->resolveIncident($incident->id, 'Root cause identified and patched');

        $postmortem = $this->service->generatePostmortem($incident->id);

        expect($postmortem)->toBeArray()
            ->and($postmortem)->toHaveKey('incident_id')
            ->and($postmortem)->toHaveKey('title')
            ->and($postmortem)->toHaveKey('resolution');
    });

    it('retrieves open incidents only', function () {
        $this->service->createIncident([
            'title'       => 'Open One',
            'description' => 'Test',
            'severity'    => 'medium',
        ]);
        $incident2 = $this->service->createIncident([
            'title'       => 'Will Close',
            'description' => 'Test',
            'severity'    => 'low',
        ]);
        $this->service->resolveIncident($incident2->id, 'Resolved');

        $open = $this->service->getOpenIncidents();

        expect($open->where('title', 'Open One'))->not->toBeEmpty()
            ->and($open->where('title', 'Will Close'))->toBeEmpty();
    });
});
