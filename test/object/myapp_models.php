<?php // @skip — companion file, no class Main

// myapp_models.php — 命名空间模型

namespace MyApp\Models;

class User
{
    public int $id;
    public string $name;
    public string $email;
    public bool $active;

    public function __construct(
        int $id,
        string $name,
        string $email,
        bool $active
    ) {
        $this->id     = $id;
        $this->name   = $name;
        $this->email  = $email;
        $this->active = $active;
    }

    public function displayName(): string
    {
        return $this->name . ' <' . $this->email . '>';
    }

    public function isActive(): bool
    {
        return $this->active;
    }
}

class Order
{
    public int $id;
    public int $userId;
    public float $amount;
    public string $status;

    public function __construct(
        int $id,
        int $userId,
        float $amount
    ) {
        $this->id     = $id;
        $this->userId = $userId;
        $this->amount = $amount;
        $this->status = 'pending';
    }

    public function approve(): void
    {
        $this->status = 'approved';
    }

    public function reject(): void
    {
        $this->status = 'rejected';
    }

    public function info(): string
    {
        return 'Order#' . $this->id . ' $' . $this->amount . ' [' . $this->status . ']';
    }
}
