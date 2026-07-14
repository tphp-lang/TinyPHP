<?php // @skip — companion file, no class Main


// OrderService
class OrderService
{
    public int $orderCount;
    public float $totalRevenue;

    public function __construct()
    {
        $this->orderCount   = 0;
        $this->totalRevenue = 0.0;
    }

    public function createItem(int $orderId, Product $p, int $qty): void
    {
        $this->orderCount   = $this->orderCount + 1;
        $this->totalRevenue = $this->totalRevenue + $p->price * (float)$qty;
    }

    public function enrich(Product $p, int $qty): EnrichedItem
    {
        return new EnrichedItem($p->sku, $p->name, $qty, $p->price);
    }

    public function safeSummary(Product $p): void
    {
        $p?->summary();
    }

    public function nullSummary(): void
    {
        $n = null;
        $n?->summary();
    }
}

// InventoryService
class InventoryService
{
    public function decrement(Product $p, int $amount): int
    {
        $p->stock = $p->stock - $amount;
        if ($p->stock <= 0) {
            $p->stock  = 0;
            $p->active = false;
        }
        return $p->stock;
    }
}
