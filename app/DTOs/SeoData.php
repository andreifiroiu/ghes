<?php

declare(strict_types=1);

namespace App\DTOs;

/**
 * The metadata one page wants in its <head>, resolved against the config
 * defaults.
 *
 * Immutable, and built only by SeoManager::resolve(). The withers exist so the
 * manager can layer a controller's overrides onto the defaults without any
 * caller holding a half-built object.
 */
final readonly class SeoData
{
    /**
     * @param  list<string>  $robots  directives for <meta name="robots">, e.g. ['noindex', 'nofollow']
     */
    public function __construct(
        public string $title,
        public ?string $description = null,
        public ?string $canonical = null,
        public ?string $image = null,
        public array $robots = [],
        public string $locale = 'ro_RO',
        public string $type = 'website',
    ) {}

    public function withTitle(string $title): self
    {
        return new self($title, $this->description, $this->canonical, $this->image, $this->robots, $this->locale, $this->type);
    }

    public function withDescription(?string $description): self
    {
        return new self($this->title, $description, $this->canonical, $this->image, $this->robots, $this->locale, $this->type);
    }

    public function withCanonical(?string $canonical): self
    {
        return new self($this->title, $this->description, $canonical, $this->image, $this->robots, $this->locale, $this->type);
    }

    public function withImage(?string $image): self
    {
        return new self($this->title, $this->description, $this->canonical, $image, $this->robots, $this->locale, $this->type);
    }

    /**
     * @param  list<string>  $robots
     */
    public function withRobots(array $robots): self
    {
        return new self($this->title, $this->description, $this->canonical, $this->image, $robots, $this->locale, $this->type);
    }

    public function withType(string $type): self
    {
        return new self($this->title, $this->description, $this->canonical, $this->image, $this->robots, $this->locale, $type);
    }
}
