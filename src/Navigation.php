<?php

namespace KodiComponents\Navigation;

use Closure;
use Illuminate\Support\Collection;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Contracts\Support\Renderable;
use KodiComponents\Navigation\Contracts\PageInterface;

class Navigation implements Renderable, Arrayable
{

    /**
     * @var PageInterface|null
     */
    protected static $current;

    /**
     * @var PageInterface|null
     */
    protected static $foundPages = [];

    /**
     * @var Collection
     */
    protected $items;

    /**
     * @var Closure
     */
    protected $accessLogic;

    public function __construct()
    {
        $this->items = new Collection();
    }

    /**
     * @param array $navigation
     */
    public function setFromArray(array $navigation)
    {
        foreach ($navigation as $page) {
            $this->addPage($page);
        }
    }

    /**
     * @param string|array|PageInterface|null $page
     *
     * @return PageInterface
     */
    public function addPage($page = null)
    {
        if (is_array($page)) {
            $page = $this->createPageFromArray($page);
        } elseif (is_string($page) or is_null($page)) {
            $page = app(PageInterface::class, [$page]);
        }

        if (! ($page instanceof PageInterface)) {
            return;
        }

        $this->getPages()->push($page);

        return $page;
    }

    /**
     * @return Collection
     */
    public function getPages()
    {
        return $this->items;
    }

    /**
     * @param Closure $callback
     *
     * @return $this
     */
    public function setPages(Closure $callback)
    {
        call_user_func($callback, $this);

        return $this;
    }

    /**
     * @param Closure $accessLogic
     *
     * @return $this
     */
    public function setAccessLogic(Closure $accessLogic)
    {
        $this->accessLogic = $accessLogic;

        return $this;
    }

    /**
     * @return Closure
     */
    public function getAccessLogic()
    {
        return is_callable($this->accessLogic)
            ? $this->accessLogic
            : true;
    }

    /**
     * @return bool
     */
    public function hasChild()
    {
        return $this->getPages()->count() > 0;
    }

    /**
     * @return PageInterface|null
     */
    public function getCurrent()
    {
        $this->findActive();

        return self::$current;
    }

    /**
     * @return array
     */
    public function toArray()
    {
        return $this->getPages();
    }

    /**
     * @return \Illuminate\View\View|\Illuminate\Contracts\View\Factory
     */
    public function render()
    {
        $this->findActive();
        $this->filterByAccessRights();
        $this->sort();

        return view(config('navigation.view.navigation', 'navigation::navigation'), [
            'pages' => $this->toArray(),
        ])->render();
    }

    public function filterByAccessRights()
    {
        $this->items = $this->getPages()->filter(function (PageInterface $page) {
            $page->filterByAccessRights();

            return $page->checkAccess();
        });
    }

    public function sort()
    {
        $this->items = $this->getPages()->sortBy(function (PageInterface $page) {
            $page->sort();

            return $page->getPriority();
        });
    }

    /**
     * @return bool
     */
    protected function findActive()
    {
        if (! is_null(self::$current)) {
            return true;
        }

        $url = url()->current();

        $this->getPages()->each(function (PageInterface $page) use ($url) {
            if (strpos($url, $page->getUrl()) !== false) {
                Navigation::$foundPages[] = [
                    levenshtein($url, $page->getUrl()),
                    $page,
                ];
            }

            $page->findActive();
        });

        $calculates = [];

        foreach (self::$foundPages as $data) {
            $calculates[] = $data[0];
        }

        if (count($calculates)) {
            self::$current = array_get(self::$foundPages, array_search(min($calculates), $calculates).'.1');
        }

        if (! is_null(self::$current)) {
            self::$current->setActive();
        }

        return false;
    }

    /**
     * @param string $title
     *
     * @return Page|false
     */
    public function findPageByTitle($title)
    {
        foreach ($this->getPages() as $page) {
            if ($page->findPageByTitle($title)) {
                return $page;
            }
        }

        return false;
    }

    /**
     * @param array $data
     *
     * @return PageInterface
     */
    protected function createPageFromArray(array $data)
    {
        $page = app(PageInterface::class);

        foreach ($data as $key => $value) {
            if ($key != 'pages' and method_exists($page, $method = 'set'.ucfirst($key))) {
                $page->{$method}($value);
            }
        }

        if (isset($data['pages']) and is_array($data['pages'])) {
            foreach ($data['pages'] as $child) {
                $page->addPage($child);
            }
        }

        return $page;
    }
}
