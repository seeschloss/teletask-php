<?php

declare(strict_types=1);

namespace Teletask;

/**
 * A room / PAGE in the .gui configuration.
 */
final class Room
{
    public int $index;
    public string $name;
    public string $source;
    public int $section;
    public bool $visible;

    /** @var array{red:int,green:int,blue:int} */
    public array $color;

    /** @var list<Item> */
    public array $items = [];

    /**
     * @param array{red:int,green:int,blue:int} $color
     */
    public function __construct(
        int $index,
        string $name,
        string $source = '',
        int $section = 0,
        bool $visible = true,
        array $color = ['red' => 255, 'green' => 255, 'blue' => 255]
    ) {
        $this->index = $index;
        $this->name = $name;
        $this->source = $source;
        $this->section = $section;
        $this->visible = $visible;
        $this->color = $color;
    }

    /**
     * Find items by name. Exact case-insensitive matches win; otherwise a
     * case-insensitive substring match is used.
     *
     * @return list<Item>
     */
    public function findItems(string $query): array
    {
        $query = trim($query);
        if ($query === '') {
            return [];
        }

        $exact = [];
        $partial = [];
        $needle = Text::lower($query);
        foreach ($this->items as $item) {
            $name = Text::lower($item->name);
            if ($name === $needle) {
                $exact[] = $item;
            } elseif (str_contains($name, $needle)) {
                $partial[] = $item;
            }
        }

        return $exact !== [] ? $exact : $partial;
    }

    /**
     * @return array<string, list<Item>>
     */
    public function itemsByCategory(): array
    {
        $result = [];
        foreach ($this->items as $item) {
            $result[$item->category][] = $item;
        }

        return $result;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'index' => $this->index,
            'name' => $this->name,
            'section' => $this->section,
            'visible' => $this->visible,
            'color' => $this->color,
            'items' => array_map(static fn (Item $item): array => $item->toArray(), $this->items),
        ];
    }
}
