<?php

namespace App\Tests\Command;

use App\Command\ParseCommentsCommand;
use App\Repository\ExtensionCommentRepository;
use App\Repository\ExtensionRepository;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Verifies the command sources its extensions from the EGO-only repository
 * method (pk IS NOT NULL) instead of loading all extensions, so GitHub-only
 * extensions never trigger a bogus EGO comments request. Uses mocked
 * collaborators only: no database, no live HTTP call.
 */
class ParseCommentsCommandTest extends TestCase
{
    public function testUsesEgoOnlyRepositoryMethodAndMakesNoRequestWhenNoneQualify(): void
    {
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $commentRepository = $this->createMock(ExtensionCommentRepository::class);
        $extensionRepository = $this->createMock(ExtensionRepository::class);

        $extensionRepository->expects(self::once())
            ->method('findAllEgoForCommentsSync')
            ->willReturn([]);

        $command = new ParseCommentsCommand($entityManager, $commentRepository, $extensionRepository);
        $tester = new CommandTester($command);
        $statusCode = $tester->execute([]);

        self::assertSame(0, $statusCode);
        self::assertStringContainsString('Processed 0 extensions', $tester->getDisplay());
    }
}
