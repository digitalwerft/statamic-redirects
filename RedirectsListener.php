<?php

namespace Statamic\Addons\Redirects;

use Statamic\API\URL;
use Statamic\Contracts\Data\Pages\Page;
use Statamic\Events\Data\ContentDeleted;
use Statamic\Events\Data\ContentSaved;
use Statamic\Events\Data\PageMoved;
use Statamic\Events\Data\PageSaved;
use Statamic\Exceptions\RedirectException;
use Statamic\Extend\Listener;
use Statamic\API\Nav;
use Statamic\API\Page as PageAPI;
use Symfony\Component\HttpFoundation\Response;

class RedirectsListener extends Listener
{
    /**
     * The events to be listened for, and the methods to call.
     *
     * @var array
     */
    public $events = [
        'cp.nav.created' => 'addNavItems',
        'response.created' => 'onResponseCreated',
        'Statamic\Events\Data\PageMoved' => 'onPageMoved',
        'Statamic\Events\Data\PageSaved' => 'onPageSaved',
        'Statamic\Events\Data\EntrySaved' => 'onContentSaved',
        'Statamic\Events\Data\TermSaved' => 'onContentSaved',
        'Statamic\Events\Data\EntryDeleted' => 'onContentDeleted',
        'Statamic\Events\Data\PageDeleted' => 'onContentDeleted',
        'Statamic\Events\Data\TermDeleted' => 'onContentDeleted',
    ];

    /**
     * Extend the main navigation of the control panel.
     *
     * @param $nav
     */
    public function addNavItems($nav)
    {
        $root = Nav::item('redirects')
            ->title('Redirects')
            ->route('redirects.index')
            ->icon('shuffle');

        $root->add(function ($item) {
            $item->add(Nav::item('redirects.manual')
                ->title($this->trans('common.manual_redirects'))
                ->route('redirects.manual.show'));

            if ($this->getConfigBool('auto_redirect_enable')) {
                $item->add(Nav::item('redirects.auto')
                    ->title($this->trans('common.auto_redirects'))
                    ->route('redirects.auto.show'));
            }

            if ($this->getConfigBool('log_404_enable')) {
                $item->add(Nav::item('redirects.404')
                    ->title($this->trans('common.monitor_404'))
                    ->route('redirects.404.show'));
            }
        });

        $nav->addTo('tools', $root);
    }

    /**
     * Check for redirects if a 404 response is created by Statamic.
     *
     * @param Response $response
     *
     * @throws RedirectException
     */
    public function onResponseCreated(Response $response)
    {
        if ($response->getStatusCode() !== 404) {
            return;
        }

        $request = request();

        app(RedirectsProcessor::class)->redirect($request);

        // If we reach this, no redirect exception has been thrown, so log the 404.
        if ($this->getConfigBool('log_404_enable')) {
            app(RedirectsLogger::class)
                ->log404($request->getPathInfo())
                ->flush();
        }
    }

    public function onPageMoved(PageMoved $event)
    {
        $this->handlePageRedirects($event->page, $event->oldPath, $event->newPath);
    }

    public function onPageSaved(PageSaved $event)
    {
        $oldPath = $event->original['attributes']['path'];
        $newPath = $event->data->path();

        $this->handlePageRedirects($event->data, $oldPath, $newPath);
    }

    public function onContentSaved(ContentSaved $event)
    {
        if (!$this->getConfigBool('auto_redirect_enable')) {
            return;
        }

        if (!$event->data->uri()) {
            return;
        }

        $slug = $event->data->slug();
        $oldSlug = $event->original['attributes']['slug'];

        if ($slug === $oldSlug) {
            $this->deleteRedirectsOfUrl($event->data->url());
            return;
        }

        $oldUrl = str_replace("/$slug", "/$oldSlug", $event->data->url());

        $autoRedirect = (new AutoRedirect())
            ->setFromUrl($oldUrl)
            ->setToUrl($event->data->url())
            ->setContentId($event->data->id());

        app(AutoRedirectsManager::class)
            ->add($autoRedirect)
            ->flush();
    }

    public function onContentDeleted(ContentDeleted $event)
    {
        $id = $event->contextualData()['id'];

        app(AutoRedirectsManager::class)
            ->removeRedirectsOfContentId($id)
            ->flush();
    }

    private function handlePageRedirects(Page $page, $oldPath, $newPath)
    {
        if (!$this->getConfigBool('auto_redirect_enable')) {
            return;
        }

        $oldUrl = URL::buildFromPath($oldPath);
        $newUrl = URL::buildFromPath($newPath);

        if ($oldUrl === $newUrl) {
            $this->deleteRedirectsOfUrl($newUrl);
            return;
        }

        $autoRedirect = (new AutoRedirect())
            ->setFromUrl($oldUrl)
            ->setToUrl($newUrl)
            ->setContentId($page->id());

        app(AutoRedirectsManager::class)->add($autoRedirect);

        $this->handlePageRedirectsRecursive($page->id(), $oldUrl, $newUrl);

        app(AutoRedirectsManager::class)->flush();
    }

    private function handlePageRedirectsRecursive($pageId, $oldUrl, $newUrl)
    {
        // Must retrieve page object via find otherwise children are not loaded...
        $page = PageAPI::find($pageId);
        $childPages = $page->children(1);

        if (!$childPages->count()) {
            return;
        }

        foreach ($childPages as $childPage) {
            $oldChildUrl = sprintf('%s/%s', $oldUrl, $childPage->slug());
            $newChildUrl = sprintf('%s/%s', $newUrl, $childPage->slug());

            $autoRedirect = (new AutoRedirect())
                ->setFromUrl($oldChildUrl)
                ->setToUrl($newChildUrl)
                ->setContentId($childPage->id());

            app(AutoRedirectsManager::class)->add($autoRedirect);

            $this->handlePageRedirectsRecursive($childPage->id(), $oldChildUrl, $newChildUrl);
        }
    }

    private function deleteRedirectsOfUrl($url)
    {
        if (app(AutoRedirectsManager::class)->exists($url)) {
            app(AutoRedirectsManager::class)
                ->remove($url)
                ->flush();
        }
    }
}