<?php

namespace App\Tests\Controller;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class ExtensionControllerTest extends WebTestCase
{
    /**
     * Test that the list route returns a 200 response with app mount element.
     */
    public function testListRouteReturnsShellWithAppMount(): void
    {
        $client = static::createClient();
        $client->request('GET', '/');

        self::assertResponseIsSuccessful();
        self::assertResponseStatusCodeSame(200);

        // Check that the response contains the app mount element
        $this->assertStringContainsString('id="app"', $client->getResponse()->getContent());
    }

    /**
     * Test that the list route exposes the feed URL.
     */
    public function testListRouteExposesFeedUrl(): void
    {
        $client = static::createClient();
        $client->request('GET', '/');

        self::assertResponseIsSuccessful();
        $content = $client->getResponse()->getContent();

        // Check that data-feed-url attribute is present
        $this->assertStringContainsString('data-feed-url', $content);
        // Should point to the public snapshot location
        $this->assertStringContainsString('/data/extensions.json', $content);
    }

    /**
     * Test that the detail route returns a 200 response with app shell.
     */
    public function testDetailRouteReturnsShell(): void
    {
        $client = static::createClient();
        $client->request('GET', '/extension/1/test-extension');

        self::assertResponseIsSuccessful();
        self::assertResponseStatusCodeSame(200);

        // Check that the response contains the app mount element
        $this->assertStringContainsString('id="app"', $client->getResponse()->getContent());
    }

    /**
     * Test that the detail route exposes the feed URL.
     */
    public function testDetailRouteExposesFeedUrl(): void
    {
        $client = static::createClient();
        $client->request('GET', '/extension/1/test-extension');

        self::assertResponseIsSuccessful();
        $content = $client->getResponse()->getContent();

        // Check that data-feed-url attribute is present
        $this->assertStringContainsString('data-feed-url', $content);
    }

    /**
     * Test that the detail route with different slugs still returns the shell.
     * The client-side app will handle slug mismatch.
     */
    public function testDetailRouteIgnoresSlugForShellRendering(): void
    {
        $client = static::createClient();
        // Note: slug is "wrong-slug" but pk is valid
        $client->request('GET', '/extension/1/wrong-slug');

        self::assertResponseIsSuccessful();
        self::assertResponseStatusCodeSame(200);
    }

    /**
     * Test that a snapshot v2 UUID-style detail path (percent-encoded) returns the shell.
     * v2 item paths are `/extension/{urlencoded uuid}`, e.g. `/extension/plaid%40plyply99`.
     */
    public function testDetailRouteAcceptsUrlEncodedUuidPath(): void
    {
        $client = static::createClient();
        $client->request('GET', '/extension/plaid%40plyply99');

        self::assertResponseIsSuccessful();
        self::assertResponseStatusCodeSame(200);
        $this->assertStringContainsString('id="app"', $client->getResponse()->getContent());
    }

    /**
     * Test that a snapshot v2 UUID-style detail path (raw, unencoded) returns the shell.
     */
    public function testDetailRouteAcceptsRawUuidPath(): void
    {
        $client = static::createClient();
        $client->request('GET', '/extension/plaid@plyply99');

        self::assertResponseIsSuccessful();
        self::assertResponseStatusCodeSame(200);
        $this->assertStringContainsString('id="app"', $client->getResponse()->getContent());
    }

    /**
     * Test that a non-numeric pk (legacy shape) still returns the shell; the
     * client resolves the extension from the URL and mismatches are handled
     * client-side, so the route itself must accept any non-slash pk segment.
     */
    public function testDetailRouteAcceptsNonNumericPkAsShell(): void
    {
        $client = static::createClient();
        $client->request('GET', '/extension/not-a-number/slug');

        self::assertResponseIsSuccessful();
        self::assertResponseStatusCodeSame(200);
    }

    /**
     * Test that a detail route with an empty pk segment still returns 404.
     */
    public function testDetailRouteWithEmptyPkSegmentReturns404(): void
    {
        $client = static::createClient();
        $client->request('GET', '/extension//slug');

        self::assertResponseStatusCodeSame(404);
    }

    /**
     * Test that the public open-data page renders and points at the snapshot.
     */
    public function testUseTheDataRouteReturnsOpenDataPage(): void
    {
        $client = static::createClient();
        $client->request('GET', '/use-the-data');

        self::assertResponseIsSuccessful();
        self::assertResponseStatusCodeSame(200);

        $content = $client->getResponse()->getContent();
        $this->assertStringContainsString('/data/extensions.v2.json', $content);
        $this->assertStringContainsString('MIT', $content);
    }

    /**
     * The browser app boots into #app and would replace this page's content.
     */
    public function testUseTheDataRouteRendersWithoutAppMount(): void
    {
        $client = static::createClient();
        $client->request('GET', '/use-the-data');

        self::assertResponseIsSuccessful();
        $this->assertStringNotContainsString('id="app"', $client->getResponse()->getContent());
    }

    /**
     * Test that the app shell header links to the open-data page.
     */
    public function testListRouteHeaderLinksToUseTheDataPage(): void
    {
        $client = static::createClient();
        $client->request('GET', '/');

        self::assertResponseIsSuccessful();
        $content = $client->getResponse()->getContent();

        $this->assertStringContainsString('href="/use-the-data"', $content);
        // Labelled, not an icon-only link.
        $this->assertStringContainsString('>About</span>', $content);
    }

    /**
     * Test that invalid paths return 404.
     */
    public function testInvalidRouteReturns404(): void
    {
        $client = static::createClient();
        $client->request('GET', '/nonexistent/route');

        self::assertResponseStatusCodeSame(404);
    }
}
