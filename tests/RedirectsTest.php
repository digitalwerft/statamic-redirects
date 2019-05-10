<?php

namespace Statamic\Addons\Redirects\tests;

use Illuminate\Foundation\Testing\TestCase;
use Statamic\Addons\Redirects\AutoRedirect;
use Statamic\Addons\Redirects\AutoRedirectsManager;
use Statamic\Addons\Redirects\ManualRedirect;
use Statamic\Addons\Redirects\ManualRedirectsManager;
use Statamic\Addons\Redirects\RedirectsLogger;
use Statamic\Addons\Redirects\RedirectsProcessor;
use Statamic\API\Page;
use Statamic\API\Stache;

/**
 * @group redirects
 *
 * Functional tests for the redirects Addon.
 * Note: We cannot extend Statamic's TestCase, as we rely on the real event system.
 */
class RedirectsTest extends TestCase
{
    /**
     * The base URL to use while testing the application.
     *
     * @var string
     */
    protected $baseUrl = 'http://localhost';

    /**
     * @var string
     */
    private $storagePath;

    /**
     * @var AutoRedirectsManager
     */
    private $autoRedirectsManager;

    /**
     * @var ManualRedirectsManager
     */
    private $manualRedirectsManager;

    /**
     * @var RedirectsLogger
     */
    private $redirectsLogger;

    /**
     * @var \Statamic\Contracts\Data\Pages\Page[]
     */
    private $pages = [];

    public function setUp()
    {
        parent::setUp();

        $this->storagePath = __DIR__ . '/temp/';
        $this->redirectsLogger = new RedirectsLogger($this->storagePath);
        $this->autoRedirectsManager = new AutoRedirectsManager($this->storagePath . 'auto.yaml', $this->redirectsLogger);
        $this->manualRedirectsManager = new ManualRedirectsManager($this->storagePath . 'manual.yaml', $this->redirectsLogger);

        // Swap our services in Laravel's service container.
        $this->app->singleton(RedirectsLogger::class, function () {
            return $this->redirectsLogger;
        });

        $this->app->singleton(AutoRedirectsManager::class, function () {
            return $this->autoRedirectsManager;
        });

        $this->app->singleton(RedirectsProcessor::class, function () {
            return new RedirectsProcessor($this->manualRedirectsManager, $this->autoRedirectsManager, $this->redirectsLogger);
        });
    }

    /**
     * @test
     */
    public function it_should_redirect_based_on_auto_redirects()
    {
        $redirect = (new AutoRedirect())
            ->setFromUrl('/not-existing-source-auto')
            ->setToUrl('/target')
            ->setContentId('1234');

        $this->autoRedirectsManager
            ->add($redirect)
            ->flush();

        $this->get('/not-existing-source-auto');

        $this->assertRedirectedTo('/target');
        $this->assertEquals(['/not-existing-source-auto' => 1], $this->redirectsLogger->getAutoRedirects());
    }

    /**
     * @test
     */
    public function it_should_redirect_based_on_manual_redirects()
    {
        $redirect = (new ManualRedirect())
            ->setFrom('/not-existing-source-manual')
            ->setTo('/target');

        $this->manualRedirectsManager
            ->add($redirect)
            ->flush();

        $this->get('/not-existing-source-manual');

        $this->assertRedirectedTo('/target');
        $this->assertEquals(['/not-existing-source-manual' => 1], $this->redirectsLogger->getManualRedirects());
    }

    /**
     * @test
     */
    public function it_should_redirect_using_placeholders()
    {
        $redirect = (new ManualRedirect())
            ->setFrom('/news/{year}/{month}/{slug}')
            ->setTo('/blog/{month}/{year}/{slug}');

        $this->manualRedirectsManager
            ->add($redirect)
            ->flush();

        $this->get('/news/2019/01/some-sluggy-slug');

        $this->assertRedirectedTo('/blog/01/2019/some-sluggy-slug');
    }

    /**
     * @test
     */
    public function it_should_redirect_to_the_url_of_existing_content()
    {
        $page = $this->createPage('/foo');

        $redirect = (new ManualRedirect())
            ->setFrom('/not-existing-source')
            ->setTo($page->id());

        $this->manualRedirectsManager
            ->add($redirect)
            ->flush();

        $this->get('/not-existing-source');

        $this->assertRedirectedTo($page->url());
    }

    /**
     * @test
     * @dataProvider timedActivationDataProvider
     */
    public function it_should_redirect_correctly_using_timed_activation($start, $end, $shouldRedirect)
    {
        $redirect = (new ManualRedirect())
            ->setFrom('/not-existing-source')
            ->setTo('/target')
            ->setStartDate($start ? new \DateTime(date('Y-m-d H:i:s', $start)) : null)
            ->setEndDate($end ? new \DateTime(date('Y-m-d H:i:s', $end)) : null);

        $this->manualRedirectsManager
            ->add($redirect)
            ->flush();

        $this->get('/not-existing-source');

        if ($shouldRedirect) {
            $this->assertRedirectedTo('/target');
            if ($start && $end) {
                $this->assertEquals(302, $this->response->getStatusCode());
            }
        } else {
            $this->assertResponseStatus(404);
        }
    }

    public function timedActivationDataProvider()
    {
        return [
            [time(), null, true],
            [null, strtotime('+1 minute'), true],
            [time(), strtotime('+1 minute'), true],
            [strtotime('-1 minute'), strtotime('+1 minute'), true],
            [strtotime('+1 hour'), null, false],
            [null, strtotime('-1 minute'), false],
            [strtotime('-1 minute'), strtotime('-1 minute'), false],
            [strtotime('+1 minute'), strtotime('+1 minute'), false],
        ];
    }

    /**
     * @test
     * @dataProvider queryStringsDataProvider
     */
    public function it_should_redirect_query_strings_to_the_target_url($shouldRetainQueryStrings, $queryStrings)
    {
        $redirect = (new ManualRedirect())
            ->setFrom('/not-existing-source')
            ->setTo('/target')
            ->setRetainQueryStrings($shouldRetainQueryStrings);

        $this->manualRedirectsManager
            ->add($redirect)
            ->flush();

        $this->get('/not-existing-source' . $queryStrings);

        $hasQueryStringsAtTargetUrl = strpos($this->response->getTargetUrl(), $queryStrings) !== false;

        if ($shouldRetainQueryStrings) {
            $this->assertTrue($hasQueryStringsAtTargetUrl);
        } else {
            $this->assertFalse($hasQueryStringsAtTargetUrl);
        }
    }

    public function queryStringsDataProvider()
    {
        return [
            [false, '?foo=bar'],
            [true, '?foo=bar'],
        ];
    }

    /**
     * @test
     * @dataProvider statusCodeDataProvider
     */
    public function it_should_redirect_using_a_correct_status_code($statusCode)
    {
        $redirect = (new ManualRedirect())
            ->setFrom('/not-existing-source')
            ->setTo('/target')
            ->setStatusCode($statusCode);

        $this->manualRedirectsManager
            ->add($redirect)
            ->flush();

        $this->get('/not-existing-source');

        $this->assertEquals($statusCode, $this->response->getStatusCode());
    }

    public function statusCodeDataProvider()
    {
        return [
            [301], [302],
        ];
    }

    /**
     * @test
     * @dataProvider localesDataProvider
     */
    public function it_should_redirect_correctly_based_on_locales($redirectLocale, $locale, $shouldRedirect)
    {
        $redirect = (new ManualRedirect())
            ->setFrom('/not-existing-source')
            ->setTo('/target')
            ->setLocale($redirectLocale);

        $this->manualRedirectsManager
            ->add($redirect)
            ->flush();

        $currentLocale = site_locale();
        if ($locale) {
            site_locale($locale);
        }

        $this->get('/not-existing-source');

        if ($shouldRedirect) {
            $this->assertRedirectedTo('/target');
        } else {
            $this->assertResponseStatus(404);
        }

        site_locale($currentLocale);
    }

    public function localesDataProvider()
    {
        return [
            [null, null, true],
            [null, 'de', true],
            ['en', 'en', true],
            ['en', 'de', false],
            ['de', 'en', false],
        ];
    }

    /**
     * @test
     */
    public function it_should_create_redirects_when_slugs_change()
    {
        $parent = $this->createPage('/parent');
        $child = $this->createPage('/parent/child');

        $parent->slug('parent-new');
        $parent->save();

        $autoRedirect = $this->autoRedirectsManager->get('/parent');
        $this->assertEquals('/parent-new', $autoRedirect->getToUrl());
        $this->assertEquals($parent->id(), $autoRedirect->getContentId());

        $this->markTestIncomplete('The Pages API does not recognize that the created parent page has a child, so the recursive creation of redirects does not work. Why? Probably a caching problem in the test environment.');

        $autoRedirect = $this->autoRedirectsManager->get('/parent/child');
        $this->assertEquals('/parent-new/child', $autoRedirect->getToUrl());
        $this->assertEquals($child->id(), $autoRedirect->getContentId());
    }

    /**
     * @test
     */
    public function it_should_create_redirects_when_pages_move()
    {
        $parent1 = $this->createPage('/parent1');
        $child1 = $this->createPage('/parent1/child1');
        $child2 = $this->createPage('/parent1/child2');
        $this->createPage('/parent2');

        // Make parent1 a child of parent2.
        $parent1->uri('/parent2/parent1');
        $parent1->save();

        $autoRedirect = $this->autoRedirectsManager->get('/parent1');
        $this->assertEquals('/parent2/parent1', $autoRedirect->getToUrl());
        $this->assertEquals($parent1->id(), $autoRedirect->getContentId());

        $this->markTestIncomplete('The Pages API does not recognize that the created parent page has children, so the recursive creation of redirects does not work. Why? Probably a caching problem in the test environment.');

        $autoRedirect = $this->autoRedirectsManager->get('/parent1/child1');
        $this->assertEquals('/parent2/parent1/child1', $autoRedirect->getToUrl());
        $this->assertEquals($child1->id(), $autoRedirect->getContentId());
    }

    /**
     * @test
     */
    public function it_should_log_404_requests()
    {
        $this->get('/not-existing-source');

        $this->assertResponseStatus(404);

        $logs = $this->redirectsLogger->get404s();

        $this->assertEquals(['/not-existing-source' => 1], $logs);
    }

    public function createApplication()
    {
        $app = require statamic_path('/bootstrap') . '/app.php';

        $app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

        return $app;
    }

    public function tearDown()
    {
        foreach ($this->pages as $page) {
            $page->delete();
        }

        @unlink($this->storagePath . 'auto.yaml');
        @unlink($this->storagePath . 'manual.yaml');
        @unlink($this->storagePath . 'log_auto.yaml');
        @unlink($this->storagePath . 'log_manual.yaml');
        @unlink($this->storagePath . 'log_404.yaml');

        parent::tearDown();
    }

    /**
     * @param string $url
     *
     * @return \Statamic\Contracts\Data\Pages\Page
     */
    private function createPage($url)
    {
        $page = Page::create($url)
            ->published(true)
            ->get()
            ->save();

        $this->pages[] = $page;

        return $page;
    }
}
