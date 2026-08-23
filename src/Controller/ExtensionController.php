<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class ExtensionController extends AbstractController
{
    /**
     * Serve the client-side app shell for the list view.
     * All filtering and rendering is done client-side.
     */
    #[Route('/', name: 'extension_list')]
    public function list(): Response
    {
        return $this->render('extension/index.html.twig');
    }

    /**
     * Serve the client-side app shell for the detail view.
     * The client-side app will resolve the extension from the URL path via client-side routing.
     * Snapshot v2 item paths are `/extension/{urlencoded uuid}` (e.g. `/extension/plaid%40plyply99`),
     * so {pk} accepts any non-slash segment, not just the legacy numeric EGO pk.
     * The optional trailing {_any} segment keeps the legacy `/extension/{pk}/{slug}` shape working.
     * Slug mismatches are handled client-side and do not affect shell rendering.
     */
    #[Route(
        '/extension/{pk}/{_any}',
        name: 'extension_show',
        requirements: ['pk' => '[^/]+', '_any' => '.*'],
        defaults: ['_any' => ''],
    )]
    public function show(): Response
    {
        return $this->render('extension/index.html.twig');
    }

    /**
     * Public open-data page: plain content, no #app mount and no app JS.
     */
    #[Route('/use-the-data', name: 'use_the_data')]
    public function useTheData(): Response
    {
        return $this->render('extension/use-the-data.html.twig');
    }
}
