<?php

declare(strict_types=1);

namespace Teletask;

/**
 * One entry on a room page. An item usually points at a Device, but camera
 * URLs and page links have no controllable device.
 */
final class Item
{
    public string $name;
    public int $cen;
    public int $fnc;
    public int $load;
    public int $icon;
    public string $category;
    public bool $readonly;
    public int $pwdProtect;
    public ?Device $device;

    /** @var array<string, mixed> */
    public array $extra;

    /**
     * @param array<string, mixed> $extra
     */
    public function __construct(
        string $name,
        int $cen,
        int $fnc,
        int $load,
        int $icon,
        bool $readonly = false,
        int $pwdProtect = 0,
        ?Device $device = null,
        array $extra = []
    ) {
        $this->name = $name;
        $this->cen = $cen;
        $this->fnc = $fnc;
        $this->load = $load;
        $this->icon = $icon;
        $this->category = Protocol::iconCategory($icon);
        $this->readonly = $readonly;
        $this->pwdProtect = $pwdProtect;
        $this->device = $device;
        $this->extra = $extra;
    }

    public function key(): string
    {
        return $this->cen . ':' . $this->fnc . ':' . $this->load;
    }

    public function address(): string
    {
        return $this->cen . '/' . $this->fnc . '/' . $this->load;
    }

    public function typeName(): string
    {
        return $this->device?->typeName() ?? Protocol::functionName($this->fnc);
    }

    /**
     * @return list<string>
     */
    public function actions(): array
    {
        if ($this->readonly || $this->device === null) {
            return [];
        }

        return $this->device->actions();
    }

    public function isControllable(): bool
    {
        return !$this->readonly
            && $this->device !== null
            && !in_array($this->fnc, [
                Protocol::FNC_NO,
                Protocol::FNC_VIDEO_DOORPHONE,
                Protocol::FNC_GOTO_PAGE,
                Protocol::FNC_CAMERA,
            ], true);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $result = [
            'name' => $this->name,
            'cen' => $this->cen,
            'fnc' => $this->fnc,
            'ld' => $this->load,
            'address' => $this->address(),
            'icon' => $this->icon,
            'category' => $this->category,
            'type' => $this->typeName(),
            'actions' => $this->actions(),
            'readonly' => $this->readonly,
            'password_protected' => $this->pwdProtect !== 0,
        ];

        if ($this->extra !== []) {
            $result['extra'] = $this->extra;
        }
        if ($this->device !== null && $this->device->meta !== []) {
            $result['device_meta'] = $this->device->meta;
        }

        return $result;
    }
}
