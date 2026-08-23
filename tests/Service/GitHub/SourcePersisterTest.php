<?php

namespace App\Tests\Service\GitHub;

use App\Entity\Extension;
use App\Entity\ExtensionSource;
use App\Entity\SourceMetricMeasurement;
use App\Repository\ExtensionSourceRepository;
use App\Repository\SourceMetricMeasurementRepository;
use App\Service\GitHub\ExtensionCandidate;
use App\Service\GitHub\SourceMapper;
use App\Service\GitHub\SourcePersister;
use DateTime;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * SourcePersister orchestration, tested with mocked Doctrine
 * collaborators so no real database connection or GitHub HTTP call is
 * needed. The pure mapping decisions themselves are covered by
 * SourceMapperTest.
 */
class SourcePersisterTest extends TestCase
{
    private function makeCandidate(array $overrides = []): ExtensionCandidate
    {
        return new ExtensionCandidate(
            repositoryId: $overrides['repositoryId'] ?? 123456,
            fullName: $overrides['fullName'] ?? 'plyply99/Plaid',
            htmlUrl: $overrides['htmlUrl'] ?? 'https://github.com/plyply99/Plaid',
            stargazersCount: $overrides['stargazersCount'] ?? 42,
            forksCount: $overrides['forksCount'] ?? 7,
            uuid: $overrides['uuid'] ?? 'plaid@plyply99',
            shellVersion: $overrides['shellVersion'] ?? ['45'],
            metadataName: $overrides['metadataName'] ?? null,
            metadataDescription: $overrides['metadataDescription'] ?? null,
            repositoryCreatedAt: $overrides['repositoryCreatedAt'] ?? null,
        );
    }

    /**
     * @return array{0: EntityManagerInterface&MockObject, 1: ExtensionSourceRepository&MockObject, 2: SourceMetricMeasurementRepository&MockObject}
     */
    private function makeCollaborators(): array
    {
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $sourceRepository = $this->createMock(ExtensionSourceRepository::class);
        $metricRepository = $this->createMock(SourceMetricMeasurementRepository::class);

        return [$entityManager, $sourceRepository, $metricRepository];
    }

    private function stubExtensionRepository(MockObject $entityManager, ?Extension $extension): void
    {
        $extensionRepository = $this->createMock(EntityRepository::class);
        $extensionRepository->method('findOneBy')->willReturn($extension);

        $entityManager->method('getRepository')->with(Extension::class)->willReturn($extensionRepository);
    }

    public function testCreatesCanonicalGithubOnlyExtensionWhenNoneExistsForTheUuid(): void
    {
        [$entityManager, $sourceRepository, $metricRepository] = $this->makeCollaborators();
        $this->stubExtensionRepository($entityManager, null);

        $sourceRepository->expects(self::never())->method('findOneByExtensionAndType');
        $sourceRepository->method('findOneByTypeAndExternalIdentifier')->willReturn(null);

        $persisted = [];
        $entityManager->method('persist')->willReturnCallback(function (object $entity) use (&$persisted): void {
            $persisted[] = $entity;
        });
        $entityManager->expects(self::once())->method('flush');

        $metricRepository->expects(self::exactly(2))->method('recordMeasurement');

        $persister = new SourcePersister($entityManager, $sourceRepository, $metricRepository, new SourceMapper());
        $candidate = $this->makeCandidate([
            'uuid' => 'plaid@plyply99',
            'fullName' => 'plyply99/Plaid',
        ]);
        $result = $persister->persistCandidate($candidate, new DateTime('2026-01-01'));

        self::assertTrue($result->success);
        self::assertNull($result->skipReason);
        self::assertCount(2, $persisted);
        self::assertInstanceOf(Extension::class, $persisted[0]);
        self::assertInstanceOf(ExtensionSource::class, $persisted[1]);

        $newExtension = $persisted[0];
        self::assertNull($newExtension->pk, 'GitHub-only extensions must not get a fake EGO pk');
        self::assertSame('plaid@plyply99', $newExtension->uuid);
        self::assertSame('plyply99/Plaid', $newExtension->name);
        self::assertSame('https://github.com/plyply99/Plaid', $newExtension->link);
        self::assertSame('https://github.com/plyply99/Plaid', $newExtension->sourceUrl);
        self::assertSame('plyply99', $newExtension->creator);
        self::assertSame('https://github.com/plyply99', $newExtension->creator_url);
        self::assertNull($newExtension->downloads);
        self::assertNull($newExtension->rating);
        self::assertNull($newExtension->comments);

        self::assertSame($newExtension, $result->source->extension);
        self::assertSame(ExtensionSource::TYPE_GITHUB, $result->source->sourceType);
    }

    public function testCanonicalCreationDateUsesRepositoryCreationDateNotImportTime(): void
    {
        [$entityManager, $sourceRepository, $metricRepository] = $this->makeCollaborators();
        $this->stubExtensionRepository($entityManager, null);
        $sourceRepository->method('findOneByTypeAndExternalIdentifier')->willReturn(null);

        $persisted = [];
        $entityManager->method('persist')->willReturnCallback(function (object $entity) use (&$persisted): void {
            $persisted[] = $entity;
        });

        $persister = new SourcePersister($entityManager, $sourceRepository, $metricRepository, new SourceMapper());
        $persister->persistCandidate($this->makeCandidate([
            'repositoryCreatedAt' => new DateTime('2026-04-08T20:53:45Z'),
        ]), new DateTime('2026-08-17T19:22:41Z'));

        self::assertSame('2026-04-08', $persisted[0]->creationDate->format('Y-m-d'));
    }

    public function testCanonicalCreationDateFallsBackToImportTimeOnlyWhenGithubReportsNone(): void
    {
        [$entityManager, $sourceRepository, $metricRepository] = $this->makeCollaborators();
        $this->stubExtensionRepository($entityManager, null);
        $sourceRepository->method('findOneByTypeAndExternalIdentifier')->willReturn(null);

        $persisted = [];
        $entityManager->method('persist')->willReturnCallback(function (object $entity) use (&$persisted): void {
            $persisted[] = $entity;
        });

        $persister = new SourcePersister($entityManager, $sourceRepository, $metricRepository, new SourceMapper());
        $persister->persistCandidate($this->makeCandidate(), new DateTime('2026-08-17T19:22:41Z'));

        self::assertSame('2026-08-17', $persisted[0]->creationDate->format('Y-m-d'));
    }

    public function testMetadataNameAndDescriptionWinOverRepositoryFullNameAndDescription(): void
    {
        [$entityManager, $sourceRepository, $metricRepository] = $this->makeCollaborators();
        $this->stubExtensionRepository($entityManager, null);
        $sourceRepository->method('findOneByTypeAndExternalIdentifier')->willReturn(null);

        $persisted = [];
        $entityManager->method('persist')->willReturnCallback(function (object $entity) use (&$persisted): void {
            $persisted[] = $entity;
        });

        $persister = new SourcePersister($entityManager, $sourceRepository, $metricRepository, new SourceMapper());
        $result = $persister->persistCandidate($this->makeCandidate([
            'fullName' => 'ryohsuke1231/liquid-glass',
            'metadataName' => 'Liquid Glass',
            'metadataDescription' => 'Applies a translucent, refractive effect.',
        ]), new DateTime('2026-01-01'));

        self::assertSame('Liquid Glass', $persisted[0]->name);
        self::assertSame('Applies a translucent, refractive effect.', $persisted[0]->description);
        self::assertSame('Liquid Glass', $result->source->displayName);
        self::assertSame('Applies a translucent, refractive effect.', $result->source->displayDescription);
    }

    /**
     * Stub the per-type source lookup, so a test can model an EGO-only,
     * GitHub-only, or dual-source extension explicitly.
     */
    private function stubSourcesByType(MockObject $sourceRepository, ?ExtensionSource $ego, ?ExtensionSource $github): void
    {
        $sourceRepository->method('findOneByExtensionAndType')->willReturnCallback(
            static fn (Extension $extension, string $sourceType): ?ExtensionSource => $sourceType === ExtensionSource::TYPE_EGO ? $ego : $github
        );
    }

    public function testAttachesGithubSourceToExistingExtensionFoundByUuidDualSource(): void
    {
        [$entityManager, $sourceRepository, $metricRepository] = $this->makeCollaborators();
        $extension = new Extension();
        $extension->id = 1;
        $extension->uuid = 'plaid@plyply99';
        $extension->name = 'Plaid';
        $extension->description = 'EGO wording wins';
        $this->stubExtensionRepository($entityManager, $extension);

        $egoSource = new ExtensionSource();
        $egoSource->id = 8;
        $egoSource->sourceType = ExtensionSource::TYPE_EGO;
        $egoSource->extension = $extension;
        $this->stubSourcesByType($sourceRepository, $egoSource, null);
        $sourceRepository->method('findOneByTypeAndExternalIdentifier')->willReturn(null);

        $entityManager->expects(self::once())->method('persist')->with(self::isInstanceOf(ExtensionSource::class));
        $entityManager->expects(self::once())->method('flush');

        $metricRepository->expects(self::exactly(2))->method('recordMeasurement')->with(
            self::isInstanceOf(ExtensionSource::class),
            self::logicalOr(SourceMetricMeasurement::METRIC_STARS, SourceMetricMeasurement::METRIC_FORKS),
            self::isType('float'),
            self::isInstanceOf(\DateTimeInterface::class),
        );

        $persister = new SourcePersister($entityManager, $sourceRepository, $metricRepository, new SourceMapper());
        $result = $persister->persistCandidate($this->makeCandidate(['metadataName' => 'GitHub wording']), new DateTime('2026-01-01'));

        self::assertTrue($result->success);
        self::assertNull($result->skipReason);
        self::assertSame($extension, $result->source->extension);
        self::assertSame(ExtensionSource::TYPE_GITHUB, $result->source->sourceType);

        self::assertSame('Plaid', $extension->name, 'EGO owns the canonical name once an EGO source exists');
        self::assertSame('EGO wording wins', $extension->description);
    }

    /**
     * A GitHub-only extension has no EGO source to own its canonical fields,
     * so a later run must be able to correct values an earlier import got
     * wrong — otherwise the very first name/description sticks forever.
     */
    public function testRefreshUpdatesCanonicalFieldsOfKnownGithubOnlyExtension(): void
    {
        [$entityManager, $sourceRepository, $metricRepository] = $this->makeCollaborators();
        $extension = new Extension();
        $extension->id = 1;
        $extension->uuid = 'liquid-glass@thinkingcoding1231.gmail.com';
        $extension->name = 'ryohsuke1231/liquid-glass';
        $extension->description = 'Gnome Shell Extension of Liquid Glass';
        $extension->creationDate = new DateTime('2026-08-17T19:22:41Z');
        $this->stubExtensionRepository($entityManager, $extension);

        $existingSource = new ExtensionSource();
        $existingSource->id = 9;
        $existingSource->sourceType = ExtensionSource::TYPE_GITHUB;
        $existingSource->extension = $extension;
        $this->stubSourcesByType($sourceRepository, null, $existingSource);

        $persister = new SourcePersister($entityManager, $sourceRepository, $metricRepository, new SourceMapper());
        $result = $persister->persistCandidate($this->makeCandidate([
            'uuid' => 'liquid-glass@thinkingcoding1231.gmail.com',
            'fullName' => 'ryohsuke1231/liquid-glass',
            'metadataName' => 'Liquid Glass',
            'metadataDescription' => 'Applies a translucent, refractive effect.',
            'repositoryCreatedAt' => new DateTime('2026-04-08T20:53:45Z'),
        ]), new DateTime('2026-08-20'));

        self::assertTrue($result->success);
        self::assertSame('Liquid Glass', $extension->name);
        self::assertSame('Applies a translucent, refractive effect.', $extension->description);
        self::assertSame('2026-04-08', $extension->creationDate->format('Y-m-d'));
    }

    public function testReusesExistingGithubSourceForTheSameExtensionInsteadOfCreatingASecondOne(): void
    {
        [$entityManager, $sourceRepository, $metricRepository] = $this->makeCollaborators();
        $extension = new Extension();
        $extension->id = 1;
        $extension->uuid = 'plaid@plyply99';
        $this->stubExtensionRepository($entityManager, $extension);

        $existingSource = new ExtensionSource();
        $existingSource->id = 9;
        $existingSource->extension = $extension;
        $sourceRepository->method('findOneByExtensionAndType')->willReturn($existingSource);
        $sourceRepository->expects(self::never())->method('findOneByTypeAndExternalIdentifier');

        $persister = new SourcePersister($entityManager, $sourceRepository, $metricRepository, new SourceMapper());
        $result = $persister->persistCandidate($this->makeCandidate(), new DateTime('2026-01-01'));

        self::assertTrue($result->success);
        self::assertSame($existingSource, $result->source, 'Must update the found source, never allocate a second one');
    }

    public function testSkipsWhenExternalIdentifierAlreadyBelongsToADifferentExtension(): void
    {
        [$entityManager, $sourceRepository, $metricRepository] = $this->makeCollaborators();
        $extension = new Extension();
        $extension->id = 1;
        $extension->uuid = 'plaid@plyply99';
        $this->stubExtensionRepository($entityManager, $extension);

        $otherExtension = new Extension();
        $otherExtension->id = 2;
        $collidingSource = new ExtensionSource();
        $collidingSource->id = 5;
        $collidingSource->extension = $otherExtension;

        $sourceRepository->method('findOneByExtensionAndType')->willReturn(null);
        $sourceRepository->method('findOneByTypeAndExternalIdentifier')->willReturn($collidingSource);

        $entityManager->expects(self::never())->method('persist');
        $metricRepository->expects(self::never())->method('recordMeasurement');

        $persister = new SourcePersister($entityManager, $sourceRepository, $metricRepository, new SourceMapper());
        $result = $persister->persistCandidate($this->makeCandidate(), new DateTime('2026-01-01'));

        self::assertFalse($result->success);
        self::assertSame(SourcePersister::SKIP_DUPLICATE_EXTERNAL_IDENTIFIER, $result->skipReason);
    }
}
