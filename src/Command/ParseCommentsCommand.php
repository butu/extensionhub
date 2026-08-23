<?php

namespace App\Command;

use App\Entity\Extension;
use App\Entity\ExtensionComment;
use App\Repository\ExtensionCommentRepository;
use App\Repository\ExtensionRepository;
use DateTime;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:parse-comments',
    description: 'Fetch comments from GNOME Extensions API, store individual reviews, and update rating aggregates',
)]
class ParseCommentsCommand extends Command
{
    public function __construct(
        protected EntityManagerInterface $entityManager,
        private ExtensionCommentRepository $commentRepository,
        private ExtensionRepository $extensionRepository,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Maximum number of extensions to process');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $limit = $input->getOption('limit');

        // EGO-only: process all pk-backed extensions (not just those with comments IS
        // NULL) so comments stay up to date. GitHub-only extensions (pk null) have no
        // EGO comments endpoint to query and are excluded by the repository.
        $extensions = $this->extensionRepository->findAllEgoForCommentsSync();

        if ($limit !== null) {
            $extensions = array_slice($extensions, 0, (int) $limit);
        }

        $cutoffDate = (new DateTime())->modify('-1 year');
        $totalCommentsSaved = 0;

        /** @var Extension $extension */
        foreach ($extensions as $extension) {
            $url = 'https://extensions.gnome.org/comments/all/?pk=' . $extension->pk . '&all=true';
            $io->note('Loading comments from ' . $url);

            try {
                $ratingSum = 0;
                $ratingCount = 0;

                $json = file_get_contents($url);
                $commentData = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

                foreach ($commentData as $comment) {
                    if (!isset($comment['rating']) || $comment['rating'] <= 0) {
                        continue;
                    }

                    if (!isset($comment['date']['timestamp']) || !isset($comment['author']['username'])) {
                        continue;
                    }

                    $commentDate = new DateTime($comment['date']['timestamp']);

                    // Skip comments older than 1 year
                    if ($commentDate < $cutoffDate) {
                        continue;
                    }

                    $ratingSum += $comment['rating'];
                    $ratingCount++;

                    // Upsert individual comment into database
                    $this->upsertComment($extension, $comment, $commentDate);
                    $totalCommentsSaved++;
                }

                // Remove comments that no longer qualify (older than 1 year)
                $this->commentRepository->removeOlderThan($extension->id, $cutoffDate);

                // Update aggregate rating on extension
                $extension->rating = $ratingCount > 0 ? $ratingSum / $ratingCount : 0;
                $extension->comments = $ratingCount;

                $this->entityManager->persist($extension);
                $this->entityManager->flush();

                // Wait 0.5 seconds so we don't overload the GNOME server
                usleep(500000);

                $io->success(sprintf(
                    'Extension %s updated: %d rated comments, avg rating %.1f',
                    $extension->name,
                    $ratingCount,
                    $extension->rating
                ));
            } catch (\JsonException $e) {
                $io->warning(sprintf('Failed to parse comments for %s: %s', $extension->name, $e->getMessage()));
            }
        }

        $io->success(sprintf('Done. Processed %d extensions, saved %d comments total.', count($extensions), $totalCommentsSaved));

        return Command::SUCCESS;
    }

    /**
     * Upsert a single comment into the database.
     * If a comment with the same extension + author + date already exists, update it.
     *
     * @param array{
     *     comment: string,
     *     rating: int,
     *     author: array{username: string, url?: string},
     *     date: array{timestamp: string},
     *     gravatar?: string,
     *     is_extension_creator?: bool
     * } $commentData
     */
    private function upsertComment(Extension $extension, array $commentData, DateTime $commentDate): void
    {
        $authorUsername = $commentData['author']['username'];

        // Look for existing comment by composite key
        $existing = $this->commentRepository->findByCompositeKey(
            $extension->id,
            $authorUsername,
            $commentDate
        );

        if ($existing !== null) {
            // Update existing comment (text, rating, or gravatar might have changed)
            $existing->comment = $commentData['comment'] ?? '';
            $existing->rating = (int) $commentData['rating'];
            $existing->gravatar = $commentData['gravatar'] ?? null;
            $existing->authorUrl = $commentData['author']['url'] ?? null;
            $existing->isExtensionCreator = (bool) ($commentData['is_extension_creator'] ?? false);
        } else {
            // Create new comment
            $comment = new ExtensionComment();
            $comment->extension = $extension;
            $comment->authorUsername = $authorUsername;
            $comment->authorUrl = $commentData['author']['url'] ?? null;
            $comment->gravatar = $commentData['gravatar'] ?? null;
            $comment->comment = $commentData['comment'] ?? '';
            $comment->rating = (int) $commentData['rating'];
            $comment->isExtensionCreator = (bool) ($commentData['is_extension_creator'] ?? false);
            $comment->commentDate = $commentDate;

            $this->entityManager->persist($comment);
        }
    }
}
