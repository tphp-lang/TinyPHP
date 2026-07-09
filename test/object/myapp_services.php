<?php // @skip — companion file, no class Main

// myapp_services.php — 命名空间服务层

namespace MyApp\Services;

use MyApp\Models\User;
use MyApp\Models\Order;

class OrderProcessor
{
    public function process(User $user, Order $order): string
    {
        if (!$user->isActive()) {
            return 'FAIL: user inactive';
        }
        if ($order->amount > 99999) {
            return 'FAIL: amount exceeds max';
        }
        $order->approve();
        return 'OK: ' . $order->info() . ' by ' . $user->displayName();
    }

    public function rejectOrder(Order $order, string $reason): void
    {
        $order->reject();
    }
}

class ReportBuilder
{
    public int $processed;
    public int $rejected;
    public float $totalAmount;

    public function __construct()
    {
        $this->processed   = 0;
        $this->rejected    = 0;
        $this->totalAmount = 0.0;
    }

    public function addProcessed(Order $order): void
    {
        $this->processed = $this->processed + 1;
        $this->totalAmount = $this->totalAmount + $order->amount;
    }

    public function addRejected(): void
    {
        $this->rejected = $this->rejected + 1;
    }

    public function summary(): string
    {
        return 'ok=' . $this->processed . ' fail=' . $this->rejected . ' total=$' . $this->totalAmount;
    }
}
